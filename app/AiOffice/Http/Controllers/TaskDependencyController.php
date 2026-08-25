<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Requests\StoreTaskDependencyRequest;
use App\AiOffice\Http\Resources\TaskResource;
use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\TaskGraph;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 替既有任務增刪相依（規格第 10 節）。
 *
 * 這是唯一可能產生循環相依的路徑——建立新任務時它還沒有任何下游，怎麼加都不會成環。
 * 所以環的偵測只需要守在這裡（以及未來 CEO 自動建圖那條路徑）。
 */
class TaskDependencyController extends Controller
{
    public function __construct(private readonly TaskGraph $graph) {}

    public function store(StoreTaskDependencyRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $dependsOnIds = array_map('intval', $request->validated('depends_on_task_ids'));

        // 檢查與寫入包在同一個交易裡：兩個請求同時各加一半的邊，各自檢查時都沒環、
        // 合起來就有環。用 lockForUpdate 把這個專案的任務列鎖住，讓檢查與寫入之間
        // 不會有別人插進來。
        DB::transaction(function () use ($task, $dependsOnIds) {
            Task::where('project_id', $task->project_id)->lockForUpdate()->pluck('id');

            if ($this->graph->wouldCreateCycle($task->id, $dependsOnIds)) {
                throw ValidationException::withMessages([
                    'depends_on_task_ids' => ['這組相依會造成循環相依，整條任務鏈會永遠等不到前置完成。'],
                ]);
            }

            // syncWithoutDetaching：重複送同一條邊是冪等的，不會因為唯一鍵爆掉，
            // 也不會把先前已經建立的其他相依洗掉。
            $task->dependencies()->syncWithoutDetaching($dependsOnIds);
        });

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->load('dependencies')))->resolve(),
        ], 201);
    }

    public function destroy(Task $task, Task $dependency): JsonResponse
    {
        $this->authorize('update', $task);

        $task->dependencies()->detach($dependency->id);

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->load('dependencies')))->resolve(),
        ]);
    }
}
