<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Restaurant;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $reviews = $restaurant->reviews()
            ->where('status', 'active')
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

    public function store(CreateReviewRequest $request, Restaurant $restaurant, ReviewService $reviews): JsonResponse
    {
        $this->authorize('create', Review::class);

        $review = $reviews->submit(
            $request->user(),
            $restaurant,
            $request->validated('rating'),
            $request->validated('comment'),
        );

        return response()->json([
            'success' => true,
            'data' => new ReviewResource($review->load('user')),
        ], 201);
    }
}
