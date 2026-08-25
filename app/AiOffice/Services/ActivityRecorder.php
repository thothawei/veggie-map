<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;

/**
 * 規格第 35 節的事件流。Phase 7 的 SSE 會直接讀這張表做增量推送；
 * 這裡先讓 AgentRuntime 有地方寫事件，否則 runtime 跑完什麼痕跡都沒留下。
 */
class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $type,
        string $description,
        ?Task $task = null,
        ?Agent $agent = null,
        array $payload = [],
        ?Project $project = null,
    ): Activity {
        return Activity::create([
            'project_id' => $task !== null ? $task->project_id : $project?->id,
            'task_id' => $task?->id,
            'agent_id' => $agent?->id,
            'type' => $type,
            'description' => $description,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
