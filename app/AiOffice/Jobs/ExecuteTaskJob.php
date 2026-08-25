<?php

namespace App\AiOffice\Jobs;

use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\AiOffice\Runtime\AgentRuntime;

/**
 * 規格第 30、31 節：Worker 撈到任務後才進 AgentRuntime。
 *
 * 不加 ShouldBeUnique：失敗重試會在同一輪 afterTaskRun 裡再 dispatch 自己，
 * unique lock 還沒放掉，第二次執行會被默默丟掉，任務卡在 assigned。
 * 重複派工靠開頭的狀態檢查＋原子搶占擋——已經 running／completed 的直接 return。
 */
class ExecuteTaskJob extends AiOfficeJob
{
    public function __construct(public int $taskId)
    {
        parent::__construct();
    }

    public function handle(AgentRuntime $runtime, AgentOrchestrator $orchestrator): void
    {
        $task = Task::query()->with('agent')->find($this->taskId);

        if ($task === null || $task->assigned_agent_id === null) {
            return;
        }

        if (! in_array($task->status, ['pending', 'assigned'], true)) {
            return;
        }

        // 兩個 worker 同時拿到同一筆 assigned 時，只讓一個人進 runtime。
        $claimed = Task::query()
            ->whereKey($task->id)
            ->whereIn('status', ['pending', 'assigned'])
            ->update(['status' => 'running']);

        if ($claimed === 0) {
            return;
        }

        $run = $runtime->run($task->fresh(['agent']));
        $orchestrator->afterTaskRun($task->fresh(), $run);
    }
}
