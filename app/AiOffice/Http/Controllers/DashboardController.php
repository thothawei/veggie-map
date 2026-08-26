<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Models\Project;
use App\AiOffice\Services\DashboardSummaryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * 規格第 50 節列的 `GET /api/dashboard`。唯讀，viewer 也看得到。
 */
class DashboardController extends Controller
{
    public function show(DashboardSummaryService $summary): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return response()->json([
            'success' => true,
            'data' => $summary->summary(),
            'meta' => [
                // 「今日」的界線是用哪個時區算的，畫面上要說得出來——否則跨時區
                // 的人會覺得數字不對又找不到原因。
                'timezone' => config('app.timezone'),
            ],
        ]);
    }
}
