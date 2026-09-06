<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendedRestaurantRequest;
use App\Http\Requests\SearchRestaurantRequest;
use App\Http\Requests\SuggestRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Repositories\RestaurantRepository;
use App\Repositories\RestaurantSuggestionRepository;
use App\Repositories\Search\KeywordSearch;
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
        $validated = $request->validated();
        $paginator = $this->restaurants->search($validated);

        $meta = [
            'per_page' => $paginator->perPage(),
            'next_cursor' => optional($paginator->nextCursor())->encode(),
            'prev_cursor' => optional($paginator->previousCursor())->encode(),
        ];

        // 「也一併搜尋了：珍奶、奶茶、手搖飲」。展開是隱形的時候，使用者搜
        // 「珍珠奶茶」看到一家叫「綠意茶飲」的店排第一只會覺得搜尋不準；說出來，
        // 它就從「怪怪的」變成「原來它懂」。問的是與 search() 同一個函式，
        // 所以這裡列出的變體就是查詢真正用到的那些（見 KeywordSearch::groupsFor）。
        $expandedTerms = KeywordSearch::expandedTerms(KeywordSearch::groupsFor(
            isset($validated['keyword']) ? (string) $validated['keyword'] : null,
            ! empty($validated['exact']),
        ));

        // 沒有展開就整個 key 不出現，而不是回空陣列——空陣列會讓前端得多寫一層
        // 「有這個 key 但它是空的」判斷，而那跟「沒展開」是同一件事。
        if ($expandedTerms !== []) {
            $meta['expanded_terms'] = $expandedTerms;
        }

        // 「放寬哪一個條件會有幾家」。**只在真的 0 筆時才算**——每一項是一次
        // COUNT(*)，有結果的查詢跑它是純粹的浪費，而且零結果本來就是少數情況。
        if ($paginator->items() === []) {
            // 零結果查詢紀錄（A8）：下一輪同義詞表要加什麼詞的依據，不是使用者追蹤
            // （不記 IP／user id／座標，見 RestaurantRepository::recordMiss）。
            $this->restaurants->recordMiss($validated);

            $relaxations = $this->restaurants->relaxations($validated);

            if ($relaxations !== []) {
                $meta['relaxations'] = $relaxations;
            }

            // 「你是不是要找…」。同樣只在 0 筆時跑，而且要有關鍵字才有意義——
            // 沒打字的人不會打錯字。**不自動改寫查詢**：使用者要自己點，
            // 否則他不會知道自己看的是另一個查詢的結果（見 DidYouMean）。
            if (! empty($validated['keyword'])) {
                $didYouMean = $this->restaurants->didYouMean((string) $validated['keyword']);

                if ($didYouMean !== []) {
                    $meta['did_you_mean'] = $didYouMean;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => RestaurantResource::collection($paginator->items())->resolve(),
            'meta' => $meta,
        ]);
    }

    /**
     * 常駐 quick filter（B3）帶「按下去會剩幾家」（B4）。吃跟 index() 一樣的
     * 篩選條件（`SearchRestaurantRequest` 共用同一份驗證規則），只是不分頁、
     * 不排序、不 eager load，純粹算 COUNT(*)。
     */
    public function facets(SearchRestaurantRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->restaurants->facets($request->validated()),
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
