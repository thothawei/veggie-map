<?php

namespace App\AiOffice\Services;

use App\AiOffice\Jobs\ProcessApprovalJob;
use App\AiOffice\Llm\LlmToolCall;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Approval;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Models\ToolExecution;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\AiOffice\Tools\ToolContext;
use App\AiOffice\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 規格第 23、24 節：沒有 approved 的 CRITICAL／高風險操作不得執行。
 * 核准與執行拆成兩個時間點——payload 存完整參數，Job 再跑工具。
 */
class ApprovalService
{
    public function __construct(
        private readonly ActivityRecorder $activities,
        private readonly ToolRegistry $tools,
        private readonly AgentOrchestrator $orchestrator,
    ) {}

    public function request(Task $task, Agent $agent, ToolExecution $execution, LlmToolCall $call): Approval
    {
        $existing = Approval::query()
            ->where('task_id', $task->id)
            ->where('action', $call->name)
            ->where('status', 'pending')
            ->first();

        if ($existing instanceof Approval) {
            return $existing;
        }

        $ttl = (int) config('ai_office.approvals.ttl_hours', 24);

        $approval = Approval::create([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'agent_id' => $agent->id,
            'tool_execution_id' => $execution->id,
            'action' => $call->name,
            'risk_level' => $execution->risk_level,
            'reason' => "{$agent->name} 請求執行 {$call->name}",
            'payload' => ['input' => $call->input],
            'status' => 'pending',
            'expires_at' => now()->addHours($ttl),
        ]);

        $this->activities->record(
            'ApprovalRequested',
            "{$agent->name} 請求核准「{$call->name}」（{$execution->risk_level}）",
            $task,
            $agent,
            ['approval_id' => $approval->id, 'action' => $call->name, 'risk_level' => $execution->risk_level],
        );

        return $approval;
    }

    public function expireOverdue(): int
    {
        return Approval::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function approve(Approval $approval, User $user, ?string $comment = null): Approval
    {
        $this->assertPending($approval);

        $approval->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'reason' => $this->appendComment($approval->reason, $comment),
        ]);

        $this->activities->record(
            'ApprovalGranted',
            "{$user->name} 核准「{$approval->action}」",
            $approval->task,
            $approval->agent,
            ['approval_id' => $approval->id],
        );

        ProcessApprovalJob::dispatch($approval->id);

        return $approval->fresh() ?? $approval;
    }

    public function reject(Approval $approval, User $user, ?string $comment = null): Approval
    {
        $this->assertPending($approval);

        DB::transaction(function () use ($approval, $user, $comment) {
            $approval->update([
                'status' => 'rejected',
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'reason' => $this->appendComment($approval->reason, $comment),
            ]);

            $approval->toolExecution?->update(['status' => 'denied']);

            $task = $approval->task;
            if ($task !== null && $task->status === 'waiting_review') {
                $task->update(['status' => 'rejected', 'error' => "核准被拒絕：{$approval->action}"]);
            }

            $agent = $approval->agent;
            if ($agent !== null && $agent->status === 'waiting_review') {
                $agent->update(['status' => 'idle']);
            }
        });

        $fresh = $approval->fresh() ?? $approval;

        $this->activities->record(
            'ApprovalRejected',
            "{$user->name} 拒絕「{$fresh->action}」",
            $fresh->task,
            $fresh->agent,
            ['approval_id' => $fresh->id],
        );

        $project = $fresh->task?->project;
        if ($project !== null) {
            $this->orchestrator->refreshProjectStatus($project);
        }

        return $fresh;
    }

    public function executeApproved(Approval $approval): void
    {
        $approval->refresh();

        if ($approval->status !== 'approved') {
            return;
        }

        $approval->loadMissing(['toolExecution.taskRun', 'task', 'agent']);

        $execution = $approval->toolExecution;
        $task = $approval->task;
        $agent = $approval->agent;

        if ($execution === null || $task === null || $agent === null) {
            return;
        }

        $tool = $this->tools->get($approval->action);
        $payload = $approval->payload ?? [];
        $rawInput = $payload['input'] ?? [];
        $input = is_array($rawInput) ? $rawInput : [];
        $taskRun = $execution->taskRun;
        $startedAt = microtime(true);

        if ($tool === null || ! $taskRun instanceof TaskRun) {
            $error = $tool === null
                ? "工具 {$approval->action} 尚未實作。"
                : '找不到對應的 task run。';
            $execution->update(['status' => 'failed', 'error' => $error]);
            $task->update(['status' => 'failed', 'error' => $error]);
            $agent->update(['status' => 'error']);

            return;
        }

        try {
            $output = $tool->execute($input, new ToolContext($agent, $task, $taskRun));
            $execution->update([
                'status' => 'succeeded',
                'output' => $output,
                'error' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
            $task->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $agent->update(['status' => 'error']);

            return;
        }

        if ($task->status === 'waiting_review') {
            $task->update(['status' => 'assigned', 'error' => null]);
        }

        if ($agent->status === 'waiting_review') {
            $agent->update(['status' => 'idle']);
        }

        $this->orchestrator->tryDispatch($task->fresh(['dependencies', 'agent']));
    }

    private function assertPending(Approval $approval): void
    {
        $this->expireOverdue();
        $approval->refresh();

        if ($approval->status === 'expired') {
            throw new ApprovalNotPendingException('這筆核准請求已過期。');
        }

        if ($approval->status !== 'pending') {
            throw new ApprovalNotPendingException('這筆核准請求已經處理過了。');
        }
    }

    private function appendComment(?string $reason, ?string $comment): ?string
    {
        $comment = is_string($comment) ? trim($comment) : '';

        if ($comment === '') {
            return $reason;
        }

        return trim((string) $reason."\n".$comment);
    }
}
