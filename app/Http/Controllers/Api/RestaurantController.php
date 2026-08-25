<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendedRestaurantRequest;
use App\Http\Requests\SearchRestaurantRequest;
use App\Http\Requests\SuggestRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Repositories\RestaurantRepository;
use App\Repositories\RestaurantSuggestionRepository;
use App\Services\Recommendation\RecommendationServiceInterface;
use Illuminate\Http\JsonResponse;

class RestaurantController extends Controller
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly RecommendationServiceInterface $recommendations,
        private readonly RestaurantSuggestionRepository $suggestions,
    ) {}

    /**
     * 搜尋建議（自動完成）。回三種型別：店名、料理種類、行政區——使用者打「日式」
     * 時要能一次選起「日式料理」這個分類，而不是只看到一串店名。
     */
    public function suggest(SuggestRestaurantRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'success' => true,
            'data' => $this->suggestions->suggest(
                (string) $validated['q'],
                isset($validated['city']) ? (string) $validated['city'] : null,
            ),
        ]);
    }

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
     * 首頁「推薦餐廳」用（見總體規劃第三十節）：候選集是同一套 search()
     * （半徑或 bbox），
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
            collect($validated)->except(['latitude', 'longitude', 'radius', 'limit'])->all(),
        );

        $ranked = $this->recommendations->rank($candidates)->take($limit);

        return response()->json([
            'success' => true,
            'data' => RestaurantResource::collection($ranked)->resolve(),
        ]);
    }

    /**
     * 詳情吃 slug 或 id。
     *
     * 規劃寫的是 `/restaurants/{slug}`（第二十六節），但既有的前端連結、分享出去的
     * 網址、以及測試都用數字 id，直接換掉會全部斷。所以兩種都收：純數字當 id，
     * 其餘當 slug——`slug` 欄位本身不可能是純數字（見 RestaurantSyncService::uniqueSlug
     * 的 fallback 是 `osm-node-123` 這種形狀），不會有歧義。
     */
    public function show(string $restaurant): JsonResponse
    {
        $model = ctype_digit($restaurant)
            ? $this->restaurants->findForDetail((int) $restaurant)
            : $this->restaurants->findForDetailBySlug($restaurant);

        abort_if($model === null, 404);

        return response()->json([
            'success' => true,
            'data' => new RestaurantResource($model),
        ]);
    }
}
