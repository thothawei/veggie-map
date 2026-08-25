<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Resources\AgentResource;
use App\AiOffice\Models\Agent;
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
