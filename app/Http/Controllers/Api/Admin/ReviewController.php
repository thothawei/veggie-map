<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Jobs\RecalculateRestaurantRatingJob;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('moderate', Review::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'hidden'])],
            'restaurant_id' => ['nullable', 'integer'],
        ]);

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $reviews = Review::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['restaurant_id'] ?? null, fn ($q, $id) => $q->where('restaurant_id', $id))
            ->with('user')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'success' => true,
            'data' => ReviewResource::collection($reviews->items())->resolve(),
            'meta' => [
                'per_page' => $reviews->perPage(),
                'next_cursor' => optional($reviews->nextCursor())->encode(),
                'prev_cursor' => optional($reviews->previousCursor())->encode(),
            ],
        ]);
    }

    public function hide(Review $review): JsonResponse
    {
        $this->authorize('moderate', $review);

        abort_if($review->status === 'hidden', 422, 'This review is already hidden.');

        $review->update(['status' => 'hidden']);

        (new RecalculateRestaurantRatingJob($review->restaurant_id))->handle();

        return response()->json([
            'success' => true,
            'data' => new ReviewResource($review->load('user')),
        ]);
    }
}
