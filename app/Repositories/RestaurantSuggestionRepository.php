<?php

namespace App\Repositories;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Repositories\Search\KeywordSearch;
use App\Support\CuisineCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * 搜尋建議（自動完成）。
 *
 * 為什麼不重用 `RestaurantRepository::search()`：建議清單要的是**三種不同型別**的
 * 候選（店名、料理種類、行政區），而 search() 只回餐廳列。用 search() 硬湊的話，
 * 使用者打「日式」只會得到一串店名，看不到「日式料理」這個可以一次選起來的分類。
 *
 * 三種建議各自的用途：
 *   restaurant → 直接跳到那家店
 *   cuisine    → 變成一次篩掉整個分類的查詢
 *   district   → 變成地區查詢
 */
class RestaurantSuggestionRepository
{
    /** 每一類最多回幾筆。清單超過這個長度就不是「建議」而是「結果」了。 */
    private const LIMIT_PER_GROUP = 5;

    /**
     * @return array{
     *     restaurants: list<array{id: int, name: string, city: string|null, district: string|null}>,
     *     cuisines: list<array{code: string, label: string}>,
     *     districts: list<array{city: string, district: string}>
     * }
     */
    public function suggest(string $query, ?string $city = null): array
    {
        $terms = KeywordSearch::terms($query);

        if ($terms === []) {
            return ['restaurants' => [], 'cuisines' => [], 'districts' => []];
        }

        // 自動完成是逐字打出來的，同一個前綴會被反覆查詢——這正是 cache 最有效的
        // 形狀。TTL 短一點（60s）：建議清單過期一分鐘沒關係，但新匯入的店應該很快
        // 出現在建議裡。
        $cacheKey = 'restaurants:suggest:'.md5(json_encode([$query, $city]));

        return Cache::tags(['restaurants'])->remember($cacheKey, 60, function () use ($terms, $city) {
            return [
                'restaurants' => $this->restaurants($terms, $city),
                'cuisines' => $this->cuisines($terms),
                'districts' => $this->districts($terms, $city),
            ];
        });
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{id: int, name: string, city: string|null, district: string|null}>
     */
    private function restaurants(array $terms, ?string $city): array
    {
        [$relevanceSql, $relevanceBindings] = KeywordSearch::relevanceExpression($terms);

        $query = Restaurant::query()->where('status', 'active');
        KeywordSearch::applyTo($query, $terms);

        if ($city !== null && $city !== '') {
            $query->where('city', $city);
        }

        // 建議清單只需要這四個欄位。撈整列（含 description／location）在自動完成
        // 這種每打一個字就查一次的路徑上特別浪費。
        return $query
            ->select(['id', 'name', 'city', 'district'])
            ->selectRaw("({$relevanceSql}) as relevance", $relevanceBindings)
            ->orderByDesc('relevance')
            ->orderBy('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Restaurant $restaurant): array => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'city' => $restaurant->city ?: null,
                'district' => $restaurant->district ?: null,
            ])
            ->all();
    }

    /**
     * 料理種類比對 config 的中文標籤與 code。只回**實際上有餐廳掛著**的分類——
     * 建議一個點下去 0 筆的分類等於騙使用者。
     *
     * @param  list<string>  $terms
     * @return list<array{code: string, label: string}>
     */
    private function cuisines(array $terms): array
    {
        $matched = [];

        foreach (CuisineCatalog::types() as $type) {
            foreach ($terms as $term) {
                if (mb_stripos($type['label'], $term) !== false || mb_stripos($type['code'], $term) !== false) {
                    $matched[$type['code']] = $type;

                    break;
                }
            }
        }

        if ($matched === []) {
            return [];
        }

        $inUse = Feature::query()
            ->whereIn('code', array_keys($matched))
            ->whereHas('restaurants', fn (Builder $q) => $q->where('status', 'active'))
            ->pluck('code')
            ->all();

        return array_values(array_intersect_key($matched, array_flip($inUse)));
    }

    /**
     * 行政區直接查 restaurants 的既有值（不是 config）：涵蓋範圍是由匯入資料決定的，
     * 寫死一份行政區清單會出現「建議了一個我們沒有資料的區」。
     *
     * @param  list<string>  $terms
     * @return list<array{city: string, district: string}>
     */
    private function districts(array $terms, ?string $city): array
    {
        $query = Restaurant::query()
            ->where('status', 'active')
            ->whereNotNull('district')
            ->where('district', '!=', '');

        foreach ($terms as $term) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
            $query->where(fn (Builder $q) => $q->where('district', 'like', $like)->orWhere('city', 'like', $like));
        }

        if ($city !== null && $city !== '') {
            $query->where('city', $city);
        }

        return $query
            ->select(['city', 'district'])
            ->distinct()
            ->orderBy('city')
            ->orderBy('district')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Restaurant $restaurant): array => [
                'city' => (string) $restaurant->city,
                'district' => (string) $restaurant->district,
            ])
            ->all();
    }
}
