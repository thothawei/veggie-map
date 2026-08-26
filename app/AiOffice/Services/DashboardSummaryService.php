<?php

namespace App\AiOffice\Services;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentError;
use App\AiOffice\Models\Approval;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * `GET /api/v1/ai-office/dashboard`（規格第 38、50 節）。
 *
 * 在這之前，儀表板的四個數字是前端從已經載入的清單自己數出來的
 * （`projects.projects.length` 之類）。兩個問題：
 *
 * 1. 那些清單是分頁的，數字會隨著「載入了幾頁」變動——規格第 74 節明講
 *    統計不能是假的，這種數字比 hardcode 更難發現是錯的。
 * 2. 規格第 38 節要的是**今日**的「完成任務／等待處理／錯誤／執行中」，
 *    前端數的是「專案數／進行中專案／工作中的 Agent／待核准」，根本不是同一組。
 *
 * 「今日」的界線用應用程式時區（`config('app.timezone')`）算，不是 UTC 硬切——
 * 不然台灣使用者早上八點看到的「今日」其實是昨天下午四點開始的那一段。
 */
class DashboardSummaryService
{
    /**
     * @return array{
     *     today: array{completed: int, waiting: int, errors: int, running: int},
     *     agents: array<string, int>,
     *     projects: array<string, int>,
     *     approvals: array{pending: int}
     * }
     */
    public function summary(): array
    {
        $since = Carbon::now(config('app.timezone'))->startOfDay()->utc();

        return [
            'today' => [
                // 完成：以「完成時間」而不是「建立時間」算——昨天派的工今天做完，
                // 算今天的產出才符合直覺。
                'completed' => Task::query()
                    ->whereIn('status', Task::TERMINAL_SUCCESS_STATUSES)
                    ->where('completed_at', '>=', $since)
                    ->count(),
                // 等待處理與執行中是「此刻的狀態」，不限今天——一個昨天卡住等核准
                // 的任務今天仍然要被看見，用 created_at 篩掉它才是真正的謊報。
                'waiting' => Task::query()->where('status', 'waiting_review')->count(),
                'errors' => AgentError::query()->where('created_at', '>=', $since)->count(),
                'running' => Task::query()->where('status', 'running')->count(),
            ],
            'agents' => $this->countByStatus(Agent::query(), Agent::STATUSES),
            'projects' => $this->countByStatus(Project::query(), Project::STATUSES),
            'approvals' => [
                'pending' => Approval::query()->where('status', 'pending')->count(),
            ],
        ];
    }

    /**
     * 每個合法狀態都要出現在結果裡（沒有的補 0）。少一個 key 的話，前端得自己
     * 寫 `?? 0`，而且看不出「是 0 還是這個狀態不存在」。
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function countByStatus($query, array $statuses): array
    {
        $counts = $query->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];

        foreach ($statuses as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }
}
