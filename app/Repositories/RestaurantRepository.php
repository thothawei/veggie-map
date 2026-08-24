<?php

namespace App\Repositories;

use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class RestaurantRepository
{
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
        $hasCoords = isset($filters['latitude'], $filters['longitude']);
        $sort = $filters['sort'] ?? ($hasCoords ? 'distance' : 'newest');
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        if ($hasCoords) {
            $lat = (float) $filters['latitude'];
            $lng = (float) $filters['longitude'];
            $radiusKm = (float) ($filters['radius'] ?? 5);

            $inner = $this->baseQuery($filters)
                ->whereRaw('MBRContains(ST_SRID(ST_GeomFromText(?), 4326), location)', [
                    $this->boundingBoxPolygon($lat, $lng, $radiusKm),
                ])
                ->selectRaw('restaurants.*, ST_Distance_Sphere(location, ST_SRID(POINT(?, ?), 4326)) as distance', [
                    $lng, $lat,
                ]);

            $query = Restaurant::query()
                ->fromSub($inner, 'restaurants')
                ->where('distance', '<=', $radiusKm * 1000);
        } else {
            $query = $this->baseQuery($filters);
        }

        $this->applySort($query, $sort, $hasCoords);

        return $query->cursorPaginate($perPage);
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

        if (! empty($filters['pet_friendly'])) {
            $query->whereHas('features', fn (Builder $q) => $q->where('code', 'pet_friendly'));
        }

        if (! empty($filters['parking'])) {
            $query->whereHas('features', fn (Builder $q) => $q->where('code', 'parking'));
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

        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta;
        $maxLng = $lng + $lngDelta;

        return "POLYGON(({$minLng} {$minLat}, {$maxLng} {$minLat}, {$maxLng} {$maxLat}, {$minLng} {$maxLat}, {$minLng} {$minLat}))";
    }
}
