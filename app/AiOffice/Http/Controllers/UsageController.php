<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Models\Project;
use App\AiOffice\Services\AgentPerformanceService;
use App\AiOffice\Services\UsageReportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 規格第 38／40 節：用量、成本、Agent 效能。全部唯讀，viewer 也看得到——
 * 「花了多少錢」是團隊資訊，不是管理員機密。
 */
class UsageController extends Controller
{
    public function __construct(
        private readonly UsageReportService $usage,
        private readonly AgentPerformanceService $performance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'agent_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->usage->report($filters),
            'meta' => [
                'filters' => [
                    'project_id' => $filters['project_id'] ?? null,
                    'agent_id' => $filters['agent_id'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                ],
                // 前端要顯示「這些成本是用哪一份價目表估的」，不然數字沒有來源。
                'pricing' => config('ai_office.llm.pricing'),
            ],
        ]);
    }

    public function agents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->performance->forAll($filters['project_id'] ?? null),
        ]);
    }
}
