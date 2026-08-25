<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RestaurantReportResource;
use App\Models\RestaurantReport;
use App\Services\ReportConsequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RestaurantReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('review', RestaurantReport::class);

        $status = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
        ])['status'] ?? 'pending';

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $reports = RestaurantReport::query()
            ->where('status', $status)
            ->with(['restaurant', 'user'])
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'success' => true,
            'data' => RestaurantReportResource::collection($reports->items())->resolve(),
            'meta' => [
                'per_page' => $reports->perPage(),
                'next_cursor' => optional($reports->nextCursor())->encode(),
                'prev_cursor' => optional($reports->previousCursor())->encode(),
            ],
        ]);
    }

    public function approve(Request $request, RestaurantReport $report): JsonResponse
    {
        return $this->review($request, $report, 'approved');
    }

    public function reject(Request $request, RestaurantReport $report): JsonResponse
    {
        return $this->review($request, $report, 'rejected');
    }

    /**
     * 核准後的連動（例如 exclusive 店 not_vegetarian → 降為 friendly）走
     * config/diet.php 的 report_actions，沒列的 type 仍是 noop——跟 Phase 7
     * 「不要猜 closed 該不該下架」同一原則，只是 Phase C 把有產品決定的兩種寫進 config。
     */
    private function review(Request $request, RestaurantReport $report, string $status): JsonResponse
    {
        $this->authorize('review', $report);

        abort_if($report->status !== 'pending', 422, 'This report has already been reviewed.');

        $report->update([
            'status' => $status,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($status === 'approved') {
            app(ReportConsequenceService::class)->apply($report->fresh(['restaurant.dietTypes']) ?? $report);
        }

        return response()->json([
            'success' => true,
            'data' => new RestaurantReportResource($report->load(['restaurant', 'user'])),
        ]);
    }
}
