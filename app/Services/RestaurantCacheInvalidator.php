<?php

namespace App\Services;

use App\Models\Restaurant;
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

        // 詳情可以用 id 或 slug 取得（第二十六節），兩者各有一份快取。只清 id 那份
        // 的話，`/restaurants/{slug}` 會繼續吐 600 秒的舊資料——而那正是前端在用的
        // 那條路徑，等於快取失效對使用者完全沒生效。
        $slug = Restaurant::withoutGlobalScopes()->whereKey($restaurantId)->value('slug');

        if ($slug !== null) {
            Cache::forget('restaurant:slug:'.$slug);
        }

        Cache::tags(['restaurants'])->flush();
    }
}
