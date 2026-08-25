<?php

namespace App\AiOffice\Runtime;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\LlmRequest;
use App\AiOffice\Llm\LlmResponse;
use App\AiOffice\Llm\LlmToolCall;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentError;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Models\ToolExecution;
use App\AiOffice\Security\PermissionGate;
use App\AiOffice\Security\RiskLevel;
use App\AiOffice\Services\ActivityRecorder;
use App\AiOffice\Services\ApprovalService;
use App\AiOffice\Services\TokenUsageService;
use App\AiOffice\Tools\ToolContext;
use App\AiOffice\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 規格第 25、26 節的 Agent 執行迴圈。
 *
 * 一次 run() = 一筆 task_runs。失敗的執行不覆蓋、不刪除，所以「第一次失敗、
 * 第二次失敗、第三次成功」這段歷史是查得到的（規格第 14 節）。
 *
 * 這個類別刻意不知道佇列的存在：它就是同步地把一個任務跑完或跑掛。
 * 生產路徑由 ExecuteTaskJob 呼叫；測試可以直接 new 出來跑。
 */
class AgentRuntime
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly ToolRegistry $tools,
        private readonly PermissionGate $gate,
        private readonly RiskLevel $risk,
        private readonly TokenUsageService $tokenUsage,
        private readonly ActivityRecorder $activities,
        private readonly ApprovalService $approvals,
    ) {}

    public function run(Task $task): TaskRun
    {
        $agent = $task->agent;

        if ($agent === null) {
            // 沒有指派 Agent 就不該進到這裡。丟例外而不是靜默地挑一個人來做
            // ——派工是 AgentSelector 的職責，不是 runtime 順手代勞的事。
            throw new RuntimeException("Task #{$task->id} 沒有指派 Agent，無法執行。");
        }

        $agent->loadMissing(['permissions', 'tools']);

        $taskRun = $this->startRun($task, $agent);
        $guard = AgentLoopGuard::fromConfig();

        try {
            return $this->loop($task, $agent, $taskRun, $guard);
        } catch (Throwable $e) {
            return $this->failRun($task, $agent, $taskRun, $guard, $e->getMessage(), $e);
        }
    }

    private function loop(Task $task, Agent $agent, TaskRun $taskRun, AgentLoopGuard $guard): TaskRun
    {
        $messages = [[
            'role' => 'user',
            'content' => $this->initialPrompt($task),
        ]];

        $toolDefinitions = $this->tools->definitionsFor($agent->tools->pluck('tool')->all());

        while ($guard->canTakeStep()) {
            $guard->recordStep();

            $response = $this->provider->send(new LlmRequest(
                systemPrompt: $agent->system_prompt,
                messages: $messages,
                tools: $toolDefinitions,
                model: $agent->model_name,
            ));

            $this->tokenUsage->record($response, $task, $taskRun);
            $guard->recordTokens($response->totalTokens());

            // 先看有沒有拿到最終答案，再看上限。順序反過來的話，剛好在最後一步
            // 撞到上限的任務會被判失敗——明明答案已經在手上了。上限的作用是
            // 「不准再繼續」，不是「把已經完成的成果丟掉」。
            if (! $response->wantsTool()) {
                return $this->completeRun($task, $agent, $taskRun, $guard, $response);
            }

            if ($breach = $guard->breach()) {
                return $this->failRun($task, $agent, $taskRun, $guard, $breach);
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->assistantContent()];

            $results = [];

            foreach ($response->toolCalls as $call) {
                $guard->recordToolCall();

                $outcome = $this->handleToolCall($call, $task, $agent, $taskRun);

                // 需要人工核准：寫一筆 Approval 之後暫停。工具還沒執行。
                // 規格第 24 節：沒有核准，CRITICAL／高風險操作不得執行。
                if ($outcome['pause']) {
                    return $this->pauseRun($task, $agent, $taskRun, $guard, $outcome['content']);
                }

                $results[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $call->id,
                    'content' => $outcome['content'],
                    'is_error' => $outcome['error'],
                ];
            }

            // 所有 tool_result 一定要放在同一則 user 訊息裡。拆成多則會讓模型
            // 慢慢學會不要平行呼叫工具，效率無聲地變差。
            $messages[] = ['role' => 'user', 'content' => $results];

            if ($breach = $guard->breach()) {
                return $this->failRun($task, $agent, $taskRun, $guard, $breach);
            }
        }

        return $this->failRun(
            $task, $agent, $taskRun, $guard,
            $guard->breach() ?? '執行迴圈在沒有得到最終答案的情況下結束。',
        );
    }

    /**
     * @return array{content: string, error: bool, pause: bool}
     */
    private function handleToolCall(LlmToolCall $call, Task $task, Agent $agent, TaskRun $taskRun): array
    {
        $tool = $this->tools->get($call->name);
        $riskLevel = $this->risk->forAbility($call->name, $tool?->riskLevel());
        $decision = $this->gate->decide($agent, $call->name, $riskLevel);

        // 權限先判、工具存在與否後判：不存在的工具如果剛好也沒授權，
        // 回報「沒有權限」比回報「沒有這個工具」更貼近實際狀況，也不會
        // 讓模型從錯誤訊息推敲出有哪些工具存在但它碰不到。
        if ($decision === PermissionGate::DENY) {
            $this->recordExecution($call, $task, $agent, $taskRun, $riskLevel, 'denied');

            return [
                'content' => "沒有執行 {$call->name} 的權限。",
                'error' => true,
                'pause' => false,
            ];
        }

        if ($decision === PermissionGate::APPROVAL) {
            $execution = $this->recordExecution($call, $task, $agent, $taskRun, $riskLevel, 'pending_approval');
            $this->approvals->request($task, $agent, $execution, $call);

            return [
                'content' => "{$call->name} 需要人工核准，任務已暫停等待審核。",
                'error' => false,
                'pause' => true,
            ];
        }

        if ($tool === null) {
            $this->recordExecution($call, $task, $agent, $taskRun, 'low', 'failed');

            return [
                'content' => "工具 {$call->name} 尚未實作。",
                'error' => true,
                'pause' => false,
            ];
        }

        $startedAt = microtime(true);

        try {
            $output = $tool->execute($call->input, new ToolContext($agent, $task, $taskRun));

            $this->recordExecution(
                $call, $task, $agent, $taskRun, $tool->riskLevel(), 'succeeded',
                output: $output,
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );

            return ['content' => json_encode($output, JSON_UNESCAPED_UNICODE), 'error' => false, 'pause' => false];
        } catch (Throwable $e) {
            $this->recordExecution(
                $call, $task, $agent, $taskRun, $tool->riskLevel(), 'failed',
                error: $e->getMessage(),
                durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            );

            // 工具壞掉不等於任務失敗——把錯誤回給模型，讓它有機會換個做法。
            return ['content' => "工具執行失敗：{$e->getMessage()}", 'error' => true, 'pause' => false];
        }
    }

    /**
     * @param  array<string, mixed>|null  $output
     */
    private function recordExecution(
        LlmToolCall $call,
        Task $task,
        Agent $agent,
        TaskRun $taskRun,
        string $riskLevel,
        string $status,
        ?array $output = null,
        ?string $error = null,
        ?int $durationMs = null,
    ): ToolExecution {
        return ToolExecution::create([
            'task_run_id' => $taskRun->id,
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'tool' => $this->tools->get($call->name)?->toolset() ?? 'unknown',
            'action' => $call->name,
            'risk_level' => $riskLevel,
            'input' => $call->input,
            'output' => $output,
            'status' => $status,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);
    }

    private function startRun(Task $task, Agent $agent): TaskRun
    {
        $runNumber = (int) $task->runs()->max('run_number') + 1;

        $task->update([
            'status' => 'running',
            'started_at' => $task->started_at ?? now(),
        ]);

        $agent->update(['status' => 'working']);

        $this->activities->record('TaskStarted', "{$agent->name} 開始執行「{$task->title}」", $task, $agent, [
            'run_number' => $runNumber,
        ]);

        return TaskRun::create([
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'run_number' => $runNumber,
            'input' => ['prompt' => $this->initialPrompt($task)],
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    private function completeRun(
        Task $task,
        Agent $agent,
        TaskRun $taskRun,
        AgentLoopGuard $guard,
        LlmResponse $response,
    ): TaskRun {
        $this->finishRun($taskRun, 'completed', $guard, output: ['text' => $response->text]);

        $task->update([
            'status' => 'completed',
            'result' => ['output' => $response->text],
            'error' => null,
            'completed_at' => now(),
        ]);

        $agent->update(['status' => 'idle']);

        $this->activities->record('TaskCompleted', "{$agent->name} 完成「{$task->title}」", $task, $agent, [
            'run_number' => $taskRun->run_number,
            'tokens' => $guard->tokens(),
        ]);

        return $taskRun->refresh();
    }

    private function pauseRun(
        Task $task,
        Agent $agent,
        TaskRun $taskRun,
        AgentLoopGuard $guard,
        string $reason,
    ): TaskRun {
        $this->finishRun($taskRun, 'cancelled', $guard, error: $reason);

        $task->update(['status' => 'waiting_review']);
        $agent->update(['status' => 'waiting_review']);

        $this->activities->record('AgentWaitingApproval', "{$agent->name}：{$reason}", $task, $agent);

        return $taskRun->refresh();
    }

    private function failRun(
        Task $task,
        Agent $agent,
        TaskRun $taskRun,
        AgentLoopGuard $guard,
        string $reason,
        ?Throwable $exception = null,
    ): TaskRun {
        $this->finishRun($taskRun, 'failed', $guard, error: $reason);

        $task->update([
            'status' => 'failed',
            'error' => $reason,
            'retry_count' => $task->retry_count + 1,
        ]);

        $agent->update(['status' => 'error']);

        AgentError::create([
            'agent_id' => $agent->id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'type' => $exception !== null ? class_basename($exception) : 'LoopLimit',
            'message' => $reason,
            'context' => [
                'run_number' => $taskRun->run_number,
                'steps' => $guard->steps(),
                'tool_calls' => $guard->toolCalls(),
                'tokens' => $guard->tokens(),
            ],
        ]);

        $this->activities->record('TaskFailed', "{$agent->name} 執行「{$task->title}」失敗：{$reason}", $task, $agent);

        // 結構化 log（規格第 55 節）。只記識別碼與統計，不記 prompt 內容，
        // 避免把 system prompt 或工具輸入原文散進 log。
        Log::warning('ai_office.task_failed', [
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'run_id' => $taskRun->id,
            'steps' => $guard->steps(),
            'tokens' => $guard->tokens(),
            'reason' => $reason,
        ]);

        return $taskRun->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $output
     */
    private function finishRun(
        TaskRun $taskRun,
        string $status,
        AgentLoopGuard $guard,
        ?array $output = null,
        ?string $error = null,
    ): void {
        $completedAt = now();

        $taskRun->update([
            'status' => $status,
            'output' => $output,
            'error' => $error,
            'completed_at' => $completedAt,
            'duration_ms' => (int) abs($taskRun->started_at->diffInMilliseconds($completedAt)),
            'token_input' => $taskRun->tokenUsageSum('input_tokens'),
            'token_output' => $taskRun->tokenUsageSum('output_tokens'),
            'estimated_cost' => $taskRun->tokenUsageSum('estimated_cost'),
        ]);
    }

    private function initialPrompt(Task $task): string
    {
        $description = $task->description ?: '（沒有補充說明）';

        return <<<PROMPT
        任務：{$task->title}

        說明：
        {$description}

        完成後直接回覆結果。需要使用工具時就呼叫工具，不要在文字裡描述你「將會」做什麼。
        PROMPT;
    }
}
