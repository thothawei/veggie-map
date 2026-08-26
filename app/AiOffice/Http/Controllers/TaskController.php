<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Requests\StoreTaskRequest;
use App\AiOffice\Http\Requests\UpdateTaskRequest;
use App\AiOffice\Http\Resources\TaskResource;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'assigned_agent_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $tasks = $project->tasks()
            ->with('dependencies')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(
                $filters['assigned_agent_id'] ?? null,
                fn ($query, $agentId) => $query->where('assigned_agent_id', $agentId),
            )
            ->orderByDesc('priority')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => TaskResource::collection($tasks)->resolve(),
            'meta' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    public function store(StoreTaskRequest $request, Project $project): JsonResponse
    {
        $this->authorize('create', Task::class);

        $validated = $request->validated();
        $dependencies = $validated['dependencies'] ?? [];
        unset($validated['dependencies']);

        // 任務與它的相依邊要嘛一起成立、要嘛都不成立：只建了任務卻沒建相依，
        // 會變成一個「看起來可以馬上跑」但其實不該跑的任務。
        $task = DB::transaction(function () use ($project, $validated, $dependencies, $request) {
            /** @var Task $task */
            $task = $project->tasks()->create($validated + [
                'created_by' => $request->user()->id,
            ]);

            // 全新任務不可能有下游，所以這裡加相依不會成環，不需要檢查
            // ——環只會在替「已經有人依賴它」的既有任務加相依時出現，
            // 那條路徑走 TaskDependencyController。
            if ($dependencies !== []) {
                $task->dependencies()->sync($dependencies);
            }

            return $task;
        });

        // 人手建立且已指派、前置也齊了才進佇列。沒指派的維持 pending，
        // 不在這裡猜角色（規格第 29 節的角色來自規劃 JSON 或呼叫端）。
        app(AgentOrchestrator::class)->tryDispatch($task->fresh(['dependencies', 'agent']));

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->load('dependencies')))->resolve(),
        ], 201);
    }

    public function show(Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $task->load(['dependencies', 'agent']);

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task))->resolve() + [
                // 派工前最想知道的一件事：前置都好了嗎（規格第 10 節）。
                'dependencies_satisfied' => $task->dependenciesSatisfied(),
            ],
        ]);
    }

    /**
     * 規格第 50 節：POST /tasks/{id}/retry。
     *
     * 跟「PATCH status=assigned」不同的是它有明確語意——只對失敗／取消的任務生效，
     * 而且不受 max_retries 限制（人按的重試，不是無人看管的自動重跑）。
     * 狀態不對就回 422，不是靜默回 200 說成功卻什麼也沒發生。
     */
    public function retry(Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        if (! in_array($task->status, Task::RETRYABLE_STATUSES, true)) {
            return $this->conflict(
                "只有失敗或已取消的任務可以重試，這一筆現在是 {$task->status}。",
            );
        }

        app(AgentOrchestrator::class)->retry($task, manual: true);

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->fresh(['dependencies', 'agent'])))->resolve(),
        ]);
    }

    /**
     * 規格第 50 節：POST /tasks/{id}/cancel。
     *
     * running 的任務是協作式取消：立刻標記，執行中的那一輪在下一個步進點才停下。
     * 回應裡的 `stops_after_current_step` 就是在說這件事——讓呼叫端知道
     * 「已取消」不等於「此刻已經停了」。
     */
    public function cancel(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        if (! in_array($task->status, Task::CANCELLABLE_STATUSES, true)) {
            return $this->conflict(
                "這一筆任務是 {$task->status}，已經結束了，不能取消。",
            );
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $wasRunning = $task->status === 'running';

        app(AgentOrchestrator::class)->cancel($task, $validated['reason'] ?? null);

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->fresh(['dependencies', 'agent'])))->resolve() + [
                'stops_after_current_step' => $wasRunning,
            ],
        ]);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => $message,
            ],
        ], 422);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $task->update($request->validated());

        // 人手改成 assigned／補上 Agent 時，跟 POST 建立任務同一條派工入口。
        // tryDispatch 自己會擋沒指派、前置未齊、Agent 忙碌的情況。
        app(AgentOrchestrator::class)->tryDispatch($task->fresh(['dependencies', 'agent']));

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->load('dependencies')))->resolve(),
        ]);
    }
}
