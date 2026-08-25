<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Resources\AgentMemoryResource;
use App\AiOffice\Http\Resources\AgentResource;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\AgentMemory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agent 目前是唯讀的（規格第 50 節只列了 GET）。Agent 的建立與設定由 seeder 管理，
 * 開放 API 建立 Agent 等於開放任意設定 system prompt 與權限，先不做。
 */
class AgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Agent::class);

        $filters = $request->validate([
            'role' => ['nullable', Rule::in(Agent::ROLES)],
            'status' => ['nullable', Rule::in(Agent::STATUSES)],
        ]);

        $agents = Agent::query()
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $agents->map(fn (Agent $agent) => (new AgentResource($agent))->resolve())->all(),
        ]);
    }

    /**
     * 這個 Agent 記得的事（規格第 41 節）。順序跟 AgentMemoryService::recall() 一致
     * ——面板上看到的前幾則，就是下次執行真的會被放進 prompt 的那幾則。
     */
    public function memories(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'memory_type' => ['nullable', Rule::in(AgentMemory::TYPES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $memories = $agent->memories()
            ->when(
                $filters['project_id'] ?? null,
                // 跨專案的通則（project_id 為 null）在任何專案下都算數，一起回。
                fn ($query, $projectId) => $query->where(
                    fn ($inner) => $inner->whereNull('project_id')->orWhere('project_id', $projectId),
                ),
            )
            ->when($filters['memory_type'] ?? null, fn ($query, $type) => $query->where('memory_type', $type))
            ->orderByDesc('importance')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => AgentMemoryResource::collection($memories)->resolve(),
            'meta' => [
                'current_page' => $memories->currentPage(),
                'last_page' => $memories->lastPage(),
                'per_page' => $memories->perPage(),
                'total' => $memories->total(),
                'recall_limit' => (int) config('ai_office.memory.recall_limit', 5),
            ],
        ]);
    }

    public function show(Agent $agent): JsonResponse
    {
        $this->authorize('view', $agent);

        $agent->load(['tools', 'permissions']);

        return response()->json([
            'success' => true,
            'data' => (new AgentResource($agent, detailed: true))->resolve(),
        ]);
    }
}
