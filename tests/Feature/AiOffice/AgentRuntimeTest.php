<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Runtime\AgentRuntime;
use App\AiOffice\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\AiOffice\RecordingTool;
use Tests\TestCase;

/**
 * 規格第 25、26 節的 Agent 執行迴圈。全部用 MockProvider 驗證，
 * 一次都不會真的呼叫 Claude API（規格第 57 節）。
 */
class AgentRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private MockProvider $llm;

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llm = new MockProvider;
        $this->app->instance(LlmProviderInterface::class, $this->llm);

        $this->registry = new ToolRegistry;
        $this->app->instance(ToolRegistry::class, $this->registry);
    }

    /**
     * @param  array<string, string>  $permissions
     * @param  list<string>  $toolsets
     */
    private function agent(array $permissions = [], array $toolsets = []): Agent
    {
        $agent = Agent::factory()->role('backend')->create(['status' => 'idle']);

        foreach ($toolsets as $toolset) {
            $agent->tools()->create(['tool' => $toolset]);
        }

        foreach ($permissions as $ability => $effect) {
            $agent->permissions()->create(['ability' => $ability, 'effect' => $effect]);
        }

        return $agent->fresh(['tools', 'permissions']);
    }

    private function task(?Agent $agent = null): Task
    {
        return Task::factory()->create([
            'project_id' => Project::factory(),
            'assigned_agent_id' => $agent?->id,
            'title' => '建立 REST API',
            'description' => '照 docs/api.md 實作',
        ]);
    }

    private function runtime(): AgentRuntime
    {
        return $this->app->make(AgentRuntime::class);
    }

    public function test_a_plain_answer_completes_the_task_and_records_the_run(): void
    {
        $agent = $this->agent();
        $task = $this->task($agent);

        $this->llm->pushText('已完成，端點在 routes/api.php。', inputTokens: 1200, outputTokens: 340);

        $run = $this->runtime()->run($task);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->run_number);
        $this->assertSame('已完成，端點在 routes/api.php。', $run->output['text']);
        $this->assertNotNull($run->completed_at);

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame('已完成，端點在 routes/api.php。', $task->result['output']);
        $this->assertNotNull($task->completed_at);

        // Agent 狀態必須是真的被寫回來的（規格第 7、46 節）。
        $this->assertSame('idle', $agent->fresh()->status);

        $this->assertDatabaseHas('ai_office_token_usages', [
            'task_run_id' => $run->id,
            'input_tokens' => 1200,
            'output_tokens' => 340,
            'total_tokens' => 1540,
        ]);

        $this->assertDatabaseHas('ai_office_activities', [
            'task_id' => $task->id,
            'type' => 'TaskCompleted',
        ]);
    }

    public function test_the_system_prompt_and_task_description_reach_the_provider(): void
    {
        $agent = $this->agent();
        $agent->update(['system_prompt' => '你是後端工程師阿明。']);
        $task = $this->task($agent->fresh(['tools', 'permissions']));

        $this->llm->pushText('好了');
        $this->runtime()->run($task);

        $request = $this->llm->received()[0];

        $this->assertSame('你是後端工程師阿明。', $request->systemPrompt);
        $this->assertStringContainsString('建立 REST API', $request->messages[0]['content']);
        $this->assertStringContainsString('照 docs/api.md 實作', $request->messages[0]['content']);
    }

    public function test_run_number_increments_and_failed_runs_are_kept(): void
    {
        $agent = $this->agent();
        $task = $this->task($agent);

        // 第一次讓 provider 丟例外（佇列是空的），第二次才排好回覆。
        $first = $this->runtime()->run($task);

        $this->assertSame(1, $first->run_number);
        $this->assertSame('failed', $first->status);
        $this->assertSame('failed', $task->fresh()->status);
        // provider 丟出來的例外要被接住並記成 AgentError，不是讓整個程序炸掉。
        $this->assertDatabaseHas('ai_office_agent_errors', [
            'task_id' => $task->id,
            'type' => 'RuntimeException',
        ]);

        $this->llm->pushText('這次好了');
        $second = $this->runtime()->run($task->fresh());

        $this->assertSame(2, $second->run_number);
        $this->assertSame('completed', $second->status);

        // 失敗的那一筆沒有被覆蓋掉，兩次執行都查得到（規格第 14 節）。
        $runs = $task->fresh()->runs()->orderBy('run_number')->get();
        $this->assertSame(2, $runs->count());
        $this->assertSame(['failed', 'completed'], $runs->pluck('status')->all());
    }

    public function test_an_unassigned_task_is_refused_instead_of_silently_picking_an_agent(): void
    {
        $task = $this->task();

        $this->expectException(RuntimeException::class);
        $this->runtime()->run($task);
    }

    public function test_an_allowed_tool_is_executed_and_its_result_goes_back_to_the_model(): void
    {
        $tool = new RecordingTool;
        $this->registry->register($tool);

        $agent = $this->agent(['read_file' => 'allow'], ['file']);
        $task = $this->task($agent);

        $this->llm->pushToolCall('read_file', ['path' => 'routes/api.php']);
        $this->llm->pushText('讀完了，內容沒問題。');

        $run = $this->runtime()->run($task);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $tool->callCount());
        $this->assertSame(['path' => 'routes/api.php'], $tool->calls[0]);

        $this->assertDatabaseHas('ai_office_tool_executions', [
            'task_run_id' => $run->id,
            'action' => 'read_file',
            'tool' => 'file',
            'status' => 'succeeded',
        ]);

        // 第二輪請求必須帶著 tool_result 回去，否則模型不知道工具跑出什麼。
        $secondRequest = $this->llm->received()[1];
        $this->assertSame('assistant', $secondRequest->messages[1]['role']);
        $this->assertSame('tool_result', $secondRequest->messages[2]['content'][0]['type']);
    }

    public function test_a_tool_without_permission_is_not_executed(): void
    {
        $tool = new RecordingTool;
        $this->registry->register($tool);

        // 權限表裡完全沒提到 read_file —— 預設拒絕。
        $agent = $this->agent([], ['file']);
        $task = $this->task($agent);

        $this->llm->pushToolCall('read_file', ['path' => '/etc/passwd']);
        $this->llm->pushText('好吧，我換個做法。');

        $run = $this->runtime()->run($task);

        // 關鍵斷言：工具一次都沒被執行。只看任務狀態證明不了這件事。
        $this->assertSame(0, $tool->callCount());
        $this->assertSame('completed', $run->status);

        $this->assertDatabaseHas('ai_office_tool_executions', [
            'action' => 'read_file',
            'status' => 'denied',
        ]);
    }

    public function test_a_tool_needing_approval_pauses_the_task_without_executing_it(): void
    {
        $tool = new RecordingTool(name: 'deploy_production', toolset: 'docker', riskLevel: 'critical');
        $this->registry->register($tool);

        $agent = $this->agent(['deploy_production' => 'approval'], ['docker']);
        $task = $this->task($agent);

        $this->llm->pushToolCall('deploy_production', ['target' => 'prod']);

        $run = $this->runtime()->run($task);

        // 沒有核准就不准執行（規格第 24 節）。
        $this->assertSame(0, $tool->callCount());
        $this->assertSame('cancelled', $run->status);
        $this->assertSame('waiting_review', $task->fresh()->status);
        $this->assertSame('waiting_review', $agent->fresh()->status);

        $this->assertDatabaseHas('ai_office_tool_executions', [
            'action' => 'deploy_production',
            'status' => 'pending_approval',
            'risk_level' => 'critical',
        ]);
        $this->assertDatabaseHas('ai_office_approvals', [
            'task_id' => $task->id,
            'action' => 'deploy_production',
            'status' => 'pending',
            'risk_level' => 'critical',
        ]);

        // 迴圈真的停在這裡：排好的回覆一則都沒被多用掉。
        $this->assertSame(0, $this->llm->pendingCount());
    }

    public function test_a_throwing_tool_does_not_kill_the_task(): void
    {
        $tool = new RecordingTool(shouldThrow: true);
        $this->registry->register($tool);

        $agent = $this->agent(['read_file' => 'allow'], ['file']);
        $task = $this->task($agent);

        $this->llm->pushToolCall('read_file', ['path' => 'nope.php']);
        $this->llm->pushText('那個檔案讀不到，我改用別的方式完成了。');

        $run = $this->runtime()->run($task);

        // 工具壞掉只是一次失敗的嘗試，錯誤回給模型讓它換做法，任務仍可完成。
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('ai_office_tool_executions', [
            'action' => 'read_file',
            'status' => 'failed',
        ]);
    }

    public function test_step_limit_stops_a_model_that_never_finishes(): void
    {
        $tool = new RecordingTool;
        $this->registry->register($tool);

        config(['ai_office.limits.max_agent_steps' => 3]);

        $agent = $this->agent(['read_file' => 'allow'], ['file']);
        $task = $this->task($agent);

        // 永遠只會叫工具、never 收手。
        foreach (range(1, 3) as $ignored) {
            $this->llm->pushToolCall('read_file', ['path' => 'a.php']);
        }

        $run = $this->runtime()->run($task);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('步數上限', $run->error);

        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame(1, $task->retry_count);
        $this->assertSame('error', $agent->fresh()->status);

        // 規格第 32 節：失敗要留下 AgentError 給 CEO 看。
        $this->assertDatabaseHas('ai_office_agent_errors', [
            'task_id' => $task->id,
            'type' => 'LoopLimit',
        ]);
    }

    public function test_token_budget_stops_the_loop(): void
    {
        $tool = new RecordingTool;
        $this->registry->register($tool);

        config(['ai_office.limits.max_token_budget' => 500]);

        $agent = $this->agent(['read_file' => 'allow'], ['file']);
        $task = $this->task($agent);

        $this->llm->pushToolCall('read_file', ['path' => 'a.php']); // 150 tokens
        $this->llm->pushToolCall('read_file', ['path' => 'b.php']); // 300
        $this->llm->pushToolCall('read_file', ['path' => 'c.php']); // 450
        $this->llm->pushToolCall('read_file', ['path' => 'd.php']); // 600 → 超過

        $run = $this->runtime()->run($task);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('token 預算', $run->error);
    }

    public function test_a_final_answer_arriving_on_the_last_allowed_step_still_completes(): void
    {
        // 上限的作用是「不准再繼續」，不是「把已經到手的答案丟掉」。
        config(['ai_office.limits.max_agent_steps' => 1]);

        $agent = $this->agent();
        $task = $this->task($agent);

        $this->llm->pushText('一步就做完了');

        $this->assertSame('completed', $this->runtime()->run($task)->status);
    }

    public function test_cost_is_estimated_from_the_configured_price_list(): void
    {
        config(['ai_office.llm.pricing.mock-1' => ['input' => 3.00, 'output' => 15.00]]);

        $agent = $this->agent();
        $task = $this->task($agent);

        $this->llm->pushText('好了', inputTokens: 1_000_000, outputTokens: 1_000_000);

        $run = $this->runtime()->run($task);

        // 100 萬 input × $3 + 100 萬 output × $15 = $18。
        // 反向驗證：改 config 的價格，這個數字必須跟著變，證明沒有寫死。
        $this->assertSame('18.000000', $run->estimated_cost);
    }

    public function test_an_unpriced_model_reports_zero_instead_of_a_guess(): void
    {
        config(['ai_office.llm.pricing' => []]);

        $agent = $this->agent();
        $task = $this->task($agent);

        $this->llm->pushText('好了', inputTokens: 999, outputTokens: 999);

        $this->assertSame('0.000000', $this->runtime()->run($task)->estimated_cost);
    }
}
