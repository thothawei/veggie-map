<?php

namespace App\Repositories;

use App\Models\Feature;
use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class RestaurantRepository
{
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
                ->with(['dietTypes', 'features', 'menuItems', 'confidenceScore'])
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
            $sort = $filters['sort'] ?? ($hasCoords ? 'distance' : 'newest');
            $perPage = min((int) ($filters['per_page'] ?? 20), 100);

            $corners = isset($filters['bbox']) ? $this->parseBbox((string) $filters['bbox']) : null;

            if ($hasCoords || $corners !== null) {
                $lat = (float) ($filters['latitude'] ?? 0);
                $lng = (float) ($filters['longitude'] ?? 0);
                $radiusKm = (float) ($filters['radius'] ?? 5);

                // bbox 優先：明確給定的矩形就是邊界本身，不需要再從半徑推一個出來。
                $inner = $this->baseQuery($filters)
                    ->whereRaw('MBRContains(ST_SRID(ST_GeomFromText(?), 4326), location)', [
                        $corners !== null
                            ? $this->polygonFromCorners(...$corners)
                            : $this->boundingBoxPolygon($lat, $lng, $radiusKm),
                    ]);

                if (! $hasCoords) {
                    // 沒有中心點就算不出距離，也就不需要外層那圈 fromSub。
                    $query = $inner;
                } else {
                    $inner->selectRaw('restaurants.*, ST_Distance_Sphere(location, ST_SRID(POINT(?, ?), 4326)) as distance', [
                        $lng, $lat,
                    ]);

                    $query = Restaurant::query()->fromSub($inner, 'restaurants');

                    // 帶 bbox 時邊界已經由矩形決定，再套半徑會把矩形四角切掉。
                    if ($corners === null) {
                        $query->where('distance', '<=', $radiusKm * 1000);
                    }
                }
            } else {
                $query = $this->baseQuery($filters);
            }

            $this->applySort($query, $sort, $hasCoords);

            return $query->cursorPaginate($perPage);
        });
    }

    private function baseQuery(array $filters): Builder
    {
        $query = Restaurant::query()->where('status', 'active');

        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('address', 'like', "%{$keyword}%");
            });
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

        if (isset($filters['price_level'])) {
            $query->where('price_level', $filters['price_level']);
        }

        if (isset($filters['rating_min'])) {
            $query->where('rating', '>=', $filters['rating_min']);
        }

        foreach (Feature::CODES as $code) {
            if (! empty($filters[$code])) {
                $query->whereHas('features', fn (Builder $q) => $q->where('code', $code));
            }
        }

        return $query;
    }

    private function applySort(Builder $query, string $sort, bool $hasCoords): void
    {
        match ($sort) {
            'distance' => $hasCoords
                ? $query->orderBy('distance')->orderBy('id')
                : $query->orderBy('id'),
            'rating' => $query->orderByDesc('rating')->orderBy('id'),
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
