<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Models\Project;
use App\AiOffice\Services\ResourceMonitorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/ai-office/resource-usage`（規格第 39、44 節：`ResourceUsage`）。
 * 唯讀，跟 UsageController 一樣 viewer 也看得到——這是系統當下狀態，不是機密。
 */
class ResourceUsageController extends Controller
{
    public function show(ResourceMonitorService $monitor): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        return response()->json([
            'success' => true,
            'data' => $monitor->snapshot(),
        ]);
    }
}
