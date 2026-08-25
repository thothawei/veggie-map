<?php

namespace App\AiOffice\Observers;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Task;
use App\AiOffice\Services\ActivityRecorder;

/**
 * Agent 狀態變動寫進事件流（Phase 7 的「Agent 狀態即時推送」）。
 *
 * Agent 不屬於任何專案，但看板是以專案為單位訂閱的，所以把事件掛到牠當下
 * 正在跑的任務所屬專案；真的沒有在跑任務時 project_id 留 null——寧可讓事件
 * 只出現在全域清單，也不要猜一個專案掛上去。
 */
class AgentStatusObserver
{
    public function __construct(private readonly ActivityRecorder $activities) {}

    public function updated(Agent $agent): void
    {
        if (! $agent->wasChanged('status')) {
            return;
        }

        $from = (string) $agent->getOriginal('status');
        $to = (string) $agent->status;

        /** @var Task|null $task */
        $task = $agent->tasks()
            ->whereIn('status', ['running', 'waiting_review', 'assigned'])
            ->orderByDesc('id')
            ->first();

        $this->activities->record(
            'AgentStatusChanged',
            "{$agent->name} 狀態 {$from} → {$to}",
            $task,
            $agent,
            ['from' => $from, 'to' => $to],
        );
    }
}
