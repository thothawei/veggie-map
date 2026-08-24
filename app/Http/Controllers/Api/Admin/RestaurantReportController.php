<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RestaurantReportResource;
use App\Models\RestaurantReport;
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
     * 只更新這筆回報自己的審核狀態，不會反過來自動改動被回報的餐廳資料
     * （例如 type=closed 不會自動把 restaurant.status 改成 inactive）——
     * docs/api.md／docs/database.md 都沒有定義這種連動規則，寧可讓 Admin
     * 自己另外去改餐廳，也不要憑空猜一個沒人要求的自動化行為。
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

        return response()->json([
            'success' => true,
            'data' => new RestaurantReportResource($report->load(['restaurant', 'user'])),
        ]);
    }
}
