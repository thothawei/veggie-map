<?php

namespace App\Repositories;

use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * `is_possible_duplicate` 的審核清單（總 Prompt 第二十二節）。
 *
 * 同步時「同名＋距離 <100m」會把兩筆都標記起來，但在這之前沒有任何地方看得到
 * 這個標記——等於標了沒人看。這個 repository 把標記還原成「可能是同一家店的
 * 群組」，Admin 才有辦法一組一組決定。
 *
 * 刻意不自動合併，也不自動刪除（規格明講）：兩筆看起來像同一家店，也可能是
 * 同一條街上的兩家分店。
 */
class DuplicateRestaurantRepository
{
    /** 與 RestaurantSyncService::flagPossibleDuplicates 同一個門檻。 */
    private const DUPLICATE_RADIUS_METERS = 100;

    /**
     * @return list<array{
     *     name: string,
     *     stale: bool,
     *     restaurants: list<array<string, mixed>>
     * }>
     */
    public function groups(): array
    {
        $flagged = Restaurant::query()
            ->where('is_possible_duplicate', true)
            ->select(['id', 'name', 'address', 'city', 'district', 'latitude', 'longitude', 'source', 'source_id', 'status', 'created_at'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($flagged->groupBy('name') as $name => $sameName) {
            foreach ($this->clusterByDistance($sameName) as $cluster) {
                $groups[] = [
                    'name' => (string) $name,
                    // 只剩一筆＝同組的另一筆已經被處理掉（下架或清標記），這個標記
                    // 是過期的。列出來讓 Admin 一鍵清掉，而不是在 GET 裡偷偷改資料。
                    'stale' => $cluster->count() === 1,
                    'restaurants' => $cluster->map(fn (Restaurant $restaurant): array => [
                        'id' => $restaurant->id,
                        'name' => $restaurant->name,
                        'address' => $restaurant->address,
                        'city' => $restaurant->city,
                        'district' => $restaurant->district,
                        'latitude' => (float) $restaurant->latitude,
                        'longitude' => (float) $restaurant->longitude,
                        'source' => $restaurant->source,
                        'source_id' => $restaurant->source_id,
                        'status' => $restaurant->status,
                        'created_at' => $restaurant->created_at,
                    ])->values()->all(),
                ];
            }
        }

        return $groups;
    }

    /**
     * 同名的餐廳再依距離分群：全台灣可能有五家同名的素食店，它們不是重複，
     * 只有「同名**且**在 100m 內」那幾筆才是同一組。
     *
     * 用貪婪分群（第一筆當種子，後面逐一比對）而不是完整的聚類演算法：標記過的
     * 筆數本來就少，而且同組通常就兩筆。
     *
     * @param  Collection<int, Restaurant>  $sameName
     * @return list<Collection<int, Restaurant>>
     */
    private function clusterByDistance(Collection $sameName): array
    {
        /** @var list<Collection<int, Restaurant>> $clusters */
        $clusters = [];

        foreach ($sameName as $restaurant) {
            $placed = false;

            foreach ($clusters as $cluster) {
                $seed = $cluster->first();

                if ($this->metersBetween($seed, $restaurant) < self::DUPLICATE_RADIUS_METERS) {
                    $cluster->push($restaurant);
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $clusters[] = new Collection([$restaurant]);
            }
        }

        return $clusters;
    }

    /** Haversine。這是分群用的近似距離，不是搜尋結果的距離（那個在 SQL 端算）。 */
    private function metersBetween(Restaurant $a, Restaurant $b): float
    {
        $earthRadius = 6371000;
        $latA = deg2rad((float) $a->latitude);
        $latB = deg2rad((float) $b->latitude);
        $deltaLat = $latB - $latA;
        $deltaLng = deg2rad((float) $b->longitude - (float) $a->longitude);

        $h = sin($deltaLat / 2) ** 2 + cos($latA) * cos($latB) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($h)));
    }
}
