<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * 共用邏輯，給 RestaurantObserver／RestaurantConfidenceScoreObserver 兩邊呼叫——
 * confidence score 存在獨立的 restaurant_confidence_scores 表（見 docs/database.md），
 * 只更新那張表不會觸發 Restaurant model 的 saved event，但 restaurant:{id} detail cache
 * 裡有內嵌 confidence_score 欄位，兩邊都要能讓同一個 restaurant 的快取失效。
 */
class RestaurantCacheInvalidator
{
    public static function invalidate(int $restaurantId): void
    {
        Cache::forget("restaurant:{$restaurantId}");
        Cache::tags(['restaurants'])->flush();
    }
}
