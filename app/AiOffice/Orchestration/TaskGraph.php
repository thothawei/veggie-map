<?php

namespace App\AiOffice\Orchestration;

use App\AiOffice\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 任務相依圖（規格第 10 節）。
 *
 * 邊的方向定義：`task_id 依賴 depends_on_task_id`，也就是箭頭從「後做的」指向
 * 「先做的」。整張圖必須是 DAG——一旦成環，任何一個節點都等不到它的前置完成，
 * 整條鏈永遠不會被排程，而且不會有任何錯誤訊息，只是安靜地卡住。所以環一定要在
 * 寫入前擋掉，不能等到跑的時候才發現。
 *
 * 資料庫層沒辦法表達這個約束（外鍵與唯一鍵都只看單一列），所以偵測寫在這裡。
 */
class TaskGraph
{
    /**
     * 如果把 $taskId 依賴 $dependsOnIds 這些邊加進去，會不會產生環？
     *
     * 判準：只要 $dependsOnIds 裡任何一個節點（沿著它自己的相依往上追）能回頭走到
     * $taskId，加上這條邊就成環。自己依賴自己是最小的環，也在這裡一併擋掉。
     *
     * @param  list<int>  $dependsOnIds
     */
    public function wouldCreateCycle(int $taskId, array $dependsOnIds): bool
    {
        foreach ($dependsOnIds as $dependsOnId) {
            if ((int) $dependsOnId === $taskId) {
                return true;
            }
        }

        // 從候選前置節點出發，往「它們依賴誰」的方向走。走得到 $taskId 就代表
        // $taskId 已經在它們的上游，再加這條邊就繞回來了。
        return in_array($taskId, $this->dependencyClosure($dependsOnIds), true);
    }

    /**
     * 從 $startIds 出發，沿著相依邊能到達的所有任務 id（不含 $startIds 本身，
     * 除非它們彼此之間本來就互相到得了）。
     *
     * 一層一次查詢（BFS），不是每個節點各查一次——一張幾百個任務的圖用遞迴逐點查
     * 會變成幾百次 round-trip。$visited 同時擋住既有資料裡萬一已經存在的環，
     * 避免這裡自己無限迴圈。
     *
     * @param  list<int>  $startIds
     * @return list<int>
     */
    public function dependencyClosure(array $startIds): array
    {
        $frontier = array_map('intval', $startIds);
        $visited = [];

        while ($frontier !== []) {
            $next = DB::table('ai_office_task_dependencies')
                ->whereIn('task_id', $frontier)
                ->pluck('depends_on_task_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $frontier = [];

            foreach ($next as $id) {
                if (! in_array($id, $visited, true)) {
                    $visited[] = $id;
                    $frontier[] = $id;
                }
            }
        }

        return $visited;
    }

    /**
     * 這個任務現在可不可以開跑：本身在等待中，而且所有前置都成功結束。
     */
    public function isReady(Task $task): bool
    {
        return $task->status === 'pending' && $task->dependenciesSatisfied();
    }

    /**
     * 專案裡目前所有可以派工的任務，優先度高的排前面。
     *
     * @return Collection<int, Task>
     */
    public function readyTasks(int $projectId): Collection
    {
        return Task::query()
            ->where('project_id', $projectId)
            ->where('status', 'pending')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->with('dependencies')
            ->get()
            ->filter(fn (Task $task) => $task->dependenciesSatisfied())
            ->values();
    }
}
