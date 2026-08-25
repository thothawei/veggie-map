<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Requests\StoreProjectRequest;
use App\AiOffice\Http\Requests\UpdateProjectRequest;
use App\AiOffice\Http\Resources\ProjectResource;
use App\AiOffice\Jobs\PlanProjectJob;
use App\AiOffice\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $projects = Project::query()
            ->withCount('tasks')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => ProjectResource::collection($projects)->resolve(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $this->authorize('create', Project::class);

        $project = Project::create($request->validated() + [
            'created_by' => $request->user()->id,
        ]);

        // workspace 路徑用 id 決定，所以只能在 insert 之後補上。存的是相對路徑，
        // 絕對位置由 config('ai_office.workspace_root') 決定——換機器、換掛載點時
        // 資料庫裡的值不用跟著改（規格第 42 節）。實體目錄等 FileTool 第一次寫入
        // 時才建立（Phase 5），這裡不碰檔案系統。
        $project->update(['workspace_path' => "project-{$project->id}"]);

        // 規格第 30 節：HTTP 只建 Project，規劃丟進佇列。測試環境 QUEUE_CONNECTION=sync
        // 仍會立刻跑完，所以 CRUD 測試要 Queue::fake()，否則沒 seed Agent 時規劃會失敗。
        PlanProjectJob::dispatch($project->id);

        return response()->json([
            'success' => true,
            'data' => (new ProjectResource($project))->resolve(),
        ], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'success' => true,
            'data' => (new ProjectResource($project->loadCount('tasks')))->resolve(),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => (new ProjectResource($project))->resolve(),
        ]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(['success' => true, 'data' => null]);
    }
}
