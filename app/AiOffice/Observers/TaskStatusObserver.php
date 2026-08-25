<?php

namespace App\AiOffice\Observers;

use App\AiOffice\Models\Task;
use App\AiOffice\Services\ActivityRecorder;

/**
 * 任務狀態變動一律寫進事件流（Phase 7 的「Task 狀態即時推送」）。
 *
 * 掛在 Model 而不是各個呼叫端：狀態會在 Controller、Orchestrator、Runtime、
 * RetryFailedTaskJob 四個地方被改，每處各補一行 record() 的話，日後多一條路徑
 * 就會靜靜地少一個事件。這裡看的是「status 欄位髒了沒」，路徑再多也漏不掉。
 *
 * 與 Runtime 既有的 TaskStarted／TaskCompleted 併存：那些帶執行細節（步數、
 * 結果摘要），這裡只回答「誰從什麼狀態變成什麼狀態」，前端用 type 分流。
 */
class TaskStatusObserver
{
    public function __construct(private readonly ActivityRecorder $activities) {}

    public function updated(Task $task): void
    {
        if (! $task->wasChanged('status')) {
            return;
        }

        $from = (string) $task->getOriginal('status');
        $to = (string) $task->status;

        $this->activities->record(
            'TaskStatusChanged',
            "任務「{$task->title}」狀態 {$from} → {$to}",
            $task,
            null,
            ['from' => $from, 'to' => $to],
        );
    }
}
