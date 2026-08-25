<?php

namespace App\AiOffice\Tools;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;

/**
 * 工具執行時的處境：誰、為了哪個任務、第幾次執行。
 * WorkspaceGuard 從這裡的 task->project 推出可存取的目錄邊界。
 */
readonly class ToolContext
{
    public function __construct(
        public Agent $agent,
        public Task $task,
        public TaskRun $taskRun,
    ) {}
}
