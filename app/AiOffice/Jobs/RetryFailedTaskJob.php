<?php

namespace App\AiOffice\Jobs;

use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\AgentOrchestrator;

/**
 * 規格第 32 節：失敗任務在 retry_count 未達上限時重新排隊。
 * AgentError 由 AgentRuntime 在每次失敗時寫入，這裡不重複建。
 */
class RetryFailedTaskJob extends AiOfficeJob
{
    public function __construct(public int $taskId)
    {
        parent::__construct();
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $task = Task::find($this->taskId);

        if ($task === null) {
            return;
        }

        $orchestrator->retry($task);
    }
}
