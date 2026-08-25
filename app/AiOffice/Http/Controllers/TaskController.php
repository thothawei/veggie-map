<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Requests\StoreTaskRequest;
use App\AiOffice\Http\Requests\UpdateTaskRequest;
use App\AiOffice\Http\Resources\TaskResource;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
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

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $task->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => (new TaskResource($task->load('dependencies')))->resolve(),
        ]);
    }
}
