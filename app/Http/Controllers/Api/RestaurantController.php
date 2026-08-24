<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendedRestaurantRequest;
use App\Http\Requests\SearchRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Repositories\RestaurantRepository;
use App\Services\Recommendation\RecommendationServiceInterface;
use Illuminate\Http\JsonResponse;

class RestaurantController extends Controller
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly RecommendationServiceInterface $recommendations,
    ) {}

    public function index(SearchRestaurantRequest $request): JsonResponse
    {
        $paginator = $this->restaurants->search($request->validated());

        return response()->json([
            'success' => true,
            'data' => RestaurantResource::collection($paginator->items())->resolve(),
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => optional($paginator->nextCursor())->encode(),
                'prev_cursor' => optional($paginator->previousCursor())->encode(),
            ],
        ]);
    }

    /**
     * 首頁「推薦餐廳」用（見總體規劃第三十節）：候選集是同一套半徑搜尋，
     * RuleBasedRecommendationService 依 distance/rating/vegetarian_confidence/
     * feature_match/popularity/freshness 加權排序，不是單純依 rating 排序。
     */
    public function recommended(RecommendedRestaurantRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $limit = $validated['limit'] ?? 6;

        $candidates = $this->restaurants->candidatesForRecommendation(
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            (float) ($validated['radius'] ?? 5),
            (int) config('recommendation.candidate_pool_size'),
        );

        $ranked = $this->recommendations->rank($candidates)->take($limit);

        return response()->json([
            'success' => true,
            'data' => RestaurantResource::collection($ranked)->resolve(),
        ]);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === 'active', 404);

        $restaurant->load(['dietTypes', 'features', 'menuItems', 'confidenceScore']);

        return response()->json([
            'success' => true,
            'data' => new RestaurantResource($restaurant),
        ]);
    }
}
