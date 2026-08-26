<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Repositories\RestaurantRepository;
use Illuminate\Support\Facades\Cache;

/**
 * 共用邏輯，給 RestaurantObserver／RestaurantConfidenceScoreObserver 兩邊呼叫——
 * confidence score 存在獨立的 restaurant_confidence_scores 表（見 docs/database.md），
 * 只更新那張表不會觸發 Restaurant model 的 saved event，但 restaurant:{id} detail cache
 * 裡有內嵌 confidence_score 欄位，兩邊都要能讓同一個 restaurant 的快取失效。
 */
class RestaurantCacheInvalidator
{
    /**
     * @param  string|null  ...$knownSlugs  呼叫端手上已經有的 slug（含改名前的舊值）。
     *                                      刪除當下 DB 列已經沒了，不能只靠再查一次。
     */
    public static function invalidate(int $restaurantId, ?string ...$knownSlugs): void
    {
        Cache::forget("restaurant:{$restaurantId}");

        $fromDb = Restaurant::withoutGlobalScopes()->whereKey($restaurantId)->value('slug');

        $slugs = array_unique(array_filter(
            [$fromDb, ...$knownSlugs],
            fn (mixed $slug): bool => is_string($slug) && $slug !== '',
        ));

        foreach ($slugs as $slug) {
            Cache::forget(RestaurantRepository::slugCacheKey($slug));
        }

        Cache::tags(['restaurants'])->flush();
    }
}
