<?php

namespace App\Repositories;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Repositories\Search\KeywordSearch;
use App\Support\CityCatalog;
use App\Support\DietCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class RestaurantRepository
{
    /**
     * 列表 API 要撈的欄位（總 Prompt 第三十二節：大型列表不要 SELECT *）。
     *
     * 刻意排除的三個：
     *   description   卡片不顯示，但它是 TEXT，每列可能好幾百 bytes
     *   source_id     只有去重與重新匯入用得到，不是給使用者看的
     *   opening_hours OSM 原始字串；列表要的是解析後的狀態，那走 openingHours 關聯
     *   location      POINT 二進位；距離已經另外算成 distance 欄位了
     *
     * `location` 沒被選出來不影響半徑搜尋——WHERE 與 SELECT 的計算欄位仍然讀得到它。
     *
     * 少掉的欄位在 RestaurantResource 裡是用 `whenHas()` 處理的：**不存在時整個
     * key 消失**，而不是回 null。回 null 的話，列表會宣稱「這家店沒有描述」，
     * 但實際上只是我們沒撈——那是安靜地說謊。
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = [
        'id', 'name', 'slug', 'address', 'city', 'district',
        'latitude', 'longitude', 'phone', 'website', 'timezone',
        'price_level', 'rating', 'rating_count', 'source', 'status',
        'is_possible_duplicate', 'created_at', 'updated_at',
    ];

    /**
     * 給 RecommendationService 用的候選集合：復用 search() 同一套半徑搜尋，取前
     * $limit 筆（依距離排序，不分頁），eager load 算分需要的關聯——不是另外重寫一套查詢。
     *
     * @param  array<string, mixed>  $filters
     * @return EloquentCollection<int, Restaurant>
     */
    public function candidatesForRecommendation(float $lat, float $lng, float $radiusKm, int $limit, array $filters = []): EloquentCollection
    {
        $paginator = $this->search([
            'latitude' => $lat,
            'longitude' => $lng,
            'radius' => $radiusKm,
            'sort' => 'distance',
            'per_page' => min($limit, 100),
            ...$filters,
        ]);

        return EloquentCollection::make($paginator->items())->load(['dietTypes', 'features', 'confidenceScore']);
    }

    /**
     * `restaurant:{id}` detail cache，TTL 600s（總體規劃第十六節）。用 implicit route
     * model binding 的話，Laravel 會在進 controller 前就先查一次 DB，等於白做了快取——
     * 所以 `RestaurantController::show()` 改用純 id，查詢本身包在這裡面。
     */
    public function findForDetail(int $id): ?Restaurant
    {
        return Cache::remember("restaurant:{$id}", 600, function () use ($id) {
            return Restaurant::query()
                ->where('status', 'active')
                ->with(['dietTypes', 'features', 'menuItems', 'confidenceScore', 'openingHours'])
                ->find($id);
        });
    }

    /**
     * 半徑搜尋兩段式查詢（見 docs/database.md）：
     * 1. Bounding Box + MBRContains 過濾 `location`，吃 Spatial Index 縮小候選集。
     * 2. 對縮小後的候選集算 ST_Distance_Sphere 精確距離，用於排序／半徑截斷。
     *
     * `distance` 是 SELECT 出來的計算欄位，MySQL 不允許在同一層 WHERE 直接引用 SELECT 別名，
     * 所以有座標時包一層 fromSub，讓外層可以對 `distance` 做 WHERE／ORDER BY／Cursor 分頁比較。
     */
    public function search(array $filters): CursorPaginator
    {
        // key 依「完整篩選條件（含 cursor/sort/per_page）」算，不同頁/不同排序各自有
        // 自己的 cache entry；見 docs/architecture.md 的 Redis Cache 設計、總體規劃第十六節
        // 的 `restaurants:search:{hash}`，TTL 300s。用 tags(['restaurants']) 而不是單純
        // key，才能在 RestaurantObserver 寫入時整批清掉，不用維護「哪些 hash 曾經存在」
        // 這種額外簿記，也不會變成總體規劃第十七節明講禁止的 `Cache::flush()` 全域清空。
        ksort($filters);
        $cacheKey = 'restaurants:search:'.md5(json_encode($filters));

        return Cache::tags(['restaurants'])->remember($cacheKey, 300, function () use ($filters) {
            $hasCoords = isset($filters['latitude'], $filters['longitude']);
            // 預設排序：有打關鍵字就以相關性優先——使用者輸入具體字詞時，想要的是
            // 「最符合的」而不是「最近的」。相關性同分時仍然依距離排（見 applySort）。
            $sort = $filters['sort'] ?? match (true) {
                ! empty($filters['keyword']) => 'relevance',
                $hasCoords => 'distance',
                default => 'newest',
            };
            $perPage = min((int) ($filters['per_page'] ?? 20), 100);

            $corners = isset($filters['bbox']) ? $this->parseBbox((string) $filters['bbox']) : null;

            $terms = ! empty($filters['keyword']) ? KeywordSearch::terms((string) $filters['keyword']) : [];
            $hasRelevance = $terms !== [];

            $lat = (float) ($filters['latitude'] ?? 0);
            $lng = (float) ($filters['longitude'] ?? 0);
            $radiusKm = (float) ($filters['radius'] ?? 5);

            $inner = $this->baseQuery($filters, $terms);

            if ($hasCoords || $corners !== null) {
                // bbox 優先：明確給定的矩形就是邊界本身，不需要再從半徑推一個出來。
                $inner->whereRaw('MBRContains(ST_SRID(ST_GeomFromText(?), 4326), location)', [
                    $corners !== null
                        ? $this->polygonFromCorners(...$corners)
                        : $this->boundingBoxPolygon($lat, $lng, $radiusKm),
                ]);
            }

            // distance 與 relevance 都是 SELECT 出來的計算欄位，MySQL 不允許在同一層
            // WHERE／ORDER BY 直接引用 SELECT 別名，所以有任何一個就包一層 fromSub。
            // bindings 的順序必須跟 SQL 字串裡 `?` 出現的順序一致（distance 在前）。
            $computed = [];
            $computedBindings = [];

            if ($hasCoords) {
                $computed[] = 'ST_Distance_Sphere(location, ST_SRID(POINT(?, ?), 4326)) as distance';
                $computedBindings[] = $lng;
                $computedBindings[] = $lat;
            }

            // 素食可信度排序（總 Prompt 第十一節）。用 correlated subquery 而不是 join：
            // 沒有分數列的餐廳要當成 0 分排在最後，join 會直接把它們整批濾掉。
            if ($sort === 'confidence') {
                $computed[] = '(SELECT COALESCE(rcs.score, 0) FROM restaurant_confidence_scores rcs'
                    .' WHERE rcs.restaurant_id = restaurants.id) as confidence';
            }

            if ($hasRelevance) {
                [$relevanceSql, $relevanceBindings] = KeywordSearch::relevanceExpression($terms);
                $computed[] = "({$relevanceSql}) as relevance";
                $computedBindings = [...$computedBindings, ...$relevanceBindings];
            }

            $columns = implode(', ', array_map(
                fn (string $column): string => "restaurants.{$column}",
                self::LIST_COLUMNS,
            ));

            if ($computed === []) {
                $inner->selectRaw($columns);
                $query = $inner;
            } else {
                $inner->selectRaw($columns.', '.implode(', ', $computed), $computedBindings);

                $query = Restaurant::query()->fromSub($inner, 'restaurants');

                // 帶 bbox 時邊界已經由矩形決定，再套半徑會把矩形四角切掉。
                if ($hasCoords && $corners === null) {
                    $query->where('distance', '<=', $radiusKm * 1000);
                }
            }

            $this->applySort($query, $sort, $hasCoords, $hasRelevance);

            // openingHours 一起載：卡片要顯示「營業中／已打烊」，逐筆補查就是 N+1。
            // confidenceScore 同理——素食可信度是這個產品的核心資訊，列表卡片就該看得到，
            // 不是點進詳情才知道。
            $query->with(['dietTypes', 'features', 'openingHours', 'confidenceScore']);

            return $query->cursorPaginate($perPage);
        });
    }

    /**
     * @param  list<string>  $terms  已斷詞的關鍵字（見 KeywordSearch::terms）
     */
    private function baseQuery(array $filters, array $terms = []): Builder
    {
        $query = Restaurant::query()->where('status', 'active');

        if ($terms !== []) {
            KeywordSearch::applyTo($query, $terms);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        if (! empty($filters['district'])) {
            $query->where('district', $filters['district']);
        }

        if (! empty($filters['diet'])) {
            $query->whereHas('dietTypes', fn (Builder $q) => $q->where('code', $filters['diet']));
        }

        DietCatalog::applyVenueScope($query, $filters[DietCatalog::venueScopeParam()] ?? null);

        if (! empty($filters['open_now'])) {
            $this->applyOpenNow($query);
        }

        if (isset($filters['price_level'])) {
            $query->where('price_level', $filters['price_level']);
        }

        if (isset($filters['rating_min'])) {
            $query->where('rating', '>=', $filters['rating_min']);
        }

        // 素食可信度下限。沒有分數列的餐廳等同 0 分，因此任何 > 0 的門檻都會把它們
        // 排除——這是對的：門檻的用途就是「只看有證據的店」。
        if (isset($filters['confidence_min'])) {
            $query->whereHas(
                'confidenceScore',
                fn (Builder $q) => $q->where('score', '>=', $filters['confidence_min']),
            );
        }

        foreach (Feature::CODES as $code) {
            if (! empty($filters[$code])) {
                $query->whereHas('features', fn (Builder $q) => $q->where('code', $code));
            }
        }

        return $query;
    }

    /**
     * 「現在營業中」。
     *
     * 兩件事決定了它的形狀：
     * 1. 判斷必須用**該店所在地**的當地時間（台北與東京差一小時），所以依
     *    restaurants.timezone 分組，每個時區各自算出「今天星期幾、現在第幾分鐘」。
     * 2. 比較下在 SQL（restaurant_opening_hours 的複合索引）而不是撈出來用 PHP 算，
     *    否則就是總 Prompt 第九節明講禁止的做法。
     *
     * 沒有解析出時段的餐廳（OSM 沒填、或寫法在解析子集之外）**不會**出現在
     * open_now 結果裡。這是刻意的：「不知道」不等於「營業中」，寧可漏掉也不要把
     * 打烊的店推給使用者。前端要標示「營業時間未知」，不要讓使用者以為是全部結果。
     */
    private function applyOpenNow(Builder $query): void
    {
        $now = CarbonImmutable::now();
        $fallback = CityCatalog::fallbackTimezone();

        $query->where(function (Builder $outer) use ($now, $fallback) {
            foreach (CityCatalog::timezones() as $timezone) {
                $local = $now->setTimezone($timezone);
                $day = $local->dayOfWeekIso - 1; // 1=Mo…7=Su → 0=Mo…6=Su
                $minutes = $local->hour * 60 + $local->minute;

                $outer->orWhere(function (Builder $q) use ($timezone, $fallback, $day, $minutes) {
                    $q->where(function (Builder $tz) use ($timezone, $fallback) {
                        $tz->where('restaurants.timezone', $timezone);

                        // timezone 是後來才加的欄位，既有資料可能是 NULL；讓它跟著
                        // 預設時區走，而不是整批從 open_now 結果裡消失。
                        if ($timezone === $fallback) {
                            $tz->orWhereNull('restaurants.timezone');
                        }
                    })->whereHas('openingHours', function ($hours) use ($day, $minutes) {
                        $hours->where('day_of_week', $day)
                            ->where('opens_at', '<=', $minutes)
                            ->where('closes_at', '>', $minutes);
                    });
                });
            }
        });
    }

    private function applySort(Builder $query, string $sort, bool $hasCoords, bool $hasRelevance = false): void
    {
        match ($sort) {
            // 相關性同分（例如兩家店都只是地址命中）就退回距離／id，否則同分的
            // 順序會由 MySQL 決定，翻頁時同一家店可能出現兩次或消失。
            'relevance' => $hasRelevance
                ? ($hasCoords
                    ? $query->orderByDesc('relevance')->orderBy('distance')->orderBy('id')
                    : $query->orderByDesc('relevance')->orderBy('id'))
                : ($hasCoords ? $query->orderBy('distance')->orderBy('id') : $query->orderBy('id')),
            'distance' => $hasCoords
                ? $query->orderBy('distance')->orderBy('id')
                : $query->orderBy('id'),
            'rating' => $query->orderByDesc('rating')->orderBy('id'),
            'confidence' => $query->orderByDesc('confidence')->orderBy('id'),
            'popular' => $query->orderByDesc('rating_count')->orderBy('id'),
            'newest' => $query->orderByDesc('id'),
            default => $query->orderBy('id'),
        };
    }

    /**
     * WKT polygon 的 bounding box，用 `ST_GeomFromText(...)`（不帶 SRID）算完再
     * `ST_SRID(..., 4326)` 貼標籤——跟 RestaurantFactory 寫入 location 用的手法一致，
     * 避開 MySQL 8 對 SRID 4326 強制的 (lat,lng) 軸序驗證。
     */
    private function boundingBoxPolygon(float $lat, float $lng, float $radiusKm): string
    {
        $latDelta = $radiusKm / 111.32;
        $lngDelta = $radiusKm / (111.32 * max(cos(deg2rad($lat)), 0.000001));

        return $this->polygonFromCorners(
            $lat - $latDelta,
            $lng - $lngDelta,
            $lat + $latDelta,
            $lng + $lngDelta,
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function parseBbox(string $bbox): ?array
    {
        $parts = array_map('trim', explode(',', $bbox));

        if (count($parts) !== 4) {
            return null;
        }

        [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);

        return [$minLat, $minLng, $maxLat, $maxLng];
    }

    /**
     * "minLat,minLng,maxLat,maxLng" → WKT polygon。城市範圍本來就是矩形（見
     * config/cities.php 與 EXTERNAL_API_SYNC_BBOXES），用 bbox 直接篩比「中心點＋半徑」
     * 精準，也不受 radius 上限 50km 的限制——台中半對角線 59.6km、高雄 66.4km，
     * 換算成半徑會直接被驗證擋下。
     */
    private function polygonFromCorners(float $minLat, float $minLng, float $maxLat, float $maxLng): string
    {
        return "POLYGON(({$minLng} {$minLat}, {$maxLng} {$minLat}, {$maxLng} {$maxLat}, {$minLng} {$maxLat}, {$minLng} {$minLat}))";
    }
}
