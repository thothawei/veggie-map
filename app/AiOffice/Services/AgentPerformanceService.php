<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TaskRun;
use App\AiOffice\Models\TokenUsage;

/**
 * 規格第 38 節的 Agent 效能統計。
 *
 * 用四個聚合查詢分別算完再在 PHP 併起來，不寫成一個大 join：任務、執行、用量
 * 是一對多再一對多，join 在一起 SUM 會把同一筆用量算好幾次——那種錯誤在報表上
 * 看起來只是「數字有點大」，很難被發現。
 */
class AgentPerformanceService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forAll(?int $projectId = null): array
    {
        $agents = Agent::query()->orderBy('id')->get();

        $taskStats = Task::query()
            ->whereNotNull('assigned_agent_id')
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->selectRaw('assigned_agent_id as agent_id')
            ->selectRaw('COUNT(*) as tasks')
            ->selectRaw("SUM(CASE WHEN status IN ('completed', 'approved') THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw('COALESCE(SUM(retry_count), 0) as retries')
            ->groupBy('assigned_agent_id')
            ->get()
            ->keyBy('agent_id');

        $runStats = TaskRun::query()
            ->when($projectId, fn ($q) => $q->whereIn(
                'task_id',
                Task::query()->where('project_id', $projectId)->select('id'),
            ))
            ->selectRaw('agent_id')
            ->selectRaw('COUNT(*) as runs')
            ->selectRaw("AVG(CASE WHEN status = 'completed' THEN duration_ms END) as avg_duration_ms")
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $usageStats = TokenUsage::query()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->selectRaw('agent_id')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(estimated_cost), 0) as estimated_cost')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        return $agents->map(function (Agent $agent) use ($taskStats, $runStats, $usageStats): array {
            $tasks = (int) ($taskStats[$agent->id]->tasks ?? 0);
            $completed = (int) ($taskStats[$agent->id]->completed ?? 0);
            $failed = (int) ($taskStats[$agent->id]->failed ?? 0);
            $avgDuration = $runStats[$agent->id]->avg_duration_ms ?? null;

            return [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'role' => $agent->role,
                'status' => $agent->status,
                'tasks' => $tasks,
                'completed' => $completed,
                'failed' => $failed,
                'retries' => (int) ($taskStats[$agent->id]->retries ?? 0),
                'runs' => (int) ($runStats[$agent->id]->runs ?? 0),
                // 沒接過任務的人回 null 而不是 0%：那兩者意義完全不同，
                // 回 0 會讓排行榜把還沒上工的人排到最後一名。
                'success_rate' => $tasks === 0 ? null : round($completed / $tasks, 4),
                'avg_duration_ms' => $avgDuration === null ? null : (int) round((float) $avgDuration),
                'total_tokens' => (int) ($usageStats[$agent->id]->total_tokens ?? 0),
                'estimated_cost' => number_format(
                    (float) ($usageStats[$agent->id]->estimated_cost ?? 0),
                    6,
                    '.',
                    '',
                ),
            ];
        })->all();
    }
}
