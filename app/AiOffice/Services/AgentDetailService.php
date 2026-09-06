<?php

namespace App\AiOffice\Services;

use App\AiOffice\Http\Resources\TaskResource;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentError;

/**
 * `GET /ai-office/agents/{id}`（規格第 47 節：`AgentDetailView`）補的那一半。
 *
 * 規格要的 Current Task／Recent Tasks／Recent Errors／Success Rate／
 * Average Duration／Token Usage 資料面幾乎都有（`ai_office_agent_errors`、
 * `ai_office_task_runs` 都在寫），缺的只是把它們聚合成一支端點的回應——
 * 之前要看單一 Agent 的效能得跨到 `/ai-office/usage` 的 `AgentPerformanceTable`
 * 湊，這裡把它併回 Agent 詳情，不用跨頁。
 */
class AgentDetailService
{
    public function __construct(private readonly AgentPerformanceService $performance) {}

    /**
     * @return array<string, mixed>
     */
    public function detail(Agent $agent, int $recentLimit = 10): array
    {
        // 現在手上正在跑的那一件——assigned／running 都算「手上有」，兩者都
        // 只可能有一筆（AgentSelector 依 max_concurrency 派工），取最新開始的
        // 那筆以防萬一有多筆（例如 max_concurrency > 1）。
        $currentTask = $agent->tasks()
            ->whereIn('status', ['assigned', 'running'])
            ->orderByDesc('started_at')
            ->first();

        $recentTasks = $agent->tasks()
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get();

        $recentErrors = $agent->errors()
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get();

        // 跟 UsageController::agents() 同一支邏輯，不另外寫一套聚合查詢——
        // Agent 數量小，為了單一 Agent 省掉三個 groupBy 查詢換來兩套要各自
        // 維護的統計邏輯不划算。
        $performance = collect($this->performance->forAll())->firstWhere('agent_id', $agent->id);

        return [
            'current_task' => $currentTask ? (new TaskResource($currentTask))->resolve() : null,
            'recent_tasks' => TaskResource::collection($recentTasks)->resolve(),
            'recent_errors' => $recentErrors->map(fn (AgentError $error) => [
                'id' => $error->id,
                'type' => $error->type,
                'message' => $error->message,
                'task_id' => $error->task_id,
                'project_id' => $error->project_id,
                'created_at' => $error->created_at?->toIso8601String(),
            ])->all(),
            'performance' => [
                'tasks' => $performance['tasks'] ?? 0,
                'completed' => $performance['completed'] ?? 0,
                'failed' => $performance['failed'] ?? 0,
                'success_rate' => $performance['success_rate'] ?? null,
                'avg_duration_ms' => $performance['avg_duration_ms'] ?? null,
                'total_tokens' => $performance['total_tokens'] ?? 0,
                'estimated_cost' => $performance['estimated_cost'] ?? '0.000000',
            ],
        ];
    }
}
