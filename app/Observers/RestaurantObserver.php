<?php

namespace App\Observers;

use App\Models\Restaurant;
use App\Services\RestaurantCacheInvalidator;

/**
 * 見總體規劃第十七節：Restaurant 修改後清除 `restaurant:{id}` 跟相關 search cache，
 * 不做 `Cache::flush()`（會清掉整個 Redis，包含跟這個 Model 無關的 cache，例如
 * geocode 的結果）。search cache 用 `Cache::tags(['restaurants'])->flush()` 整批清，
 * 不是逐一算出每個可能的 filter 組合去 `forget()`——那組合數不可能窮舉。
 *
 * 掛在 model event 上（而不是要求每個寫入路徑自己記得呼叫清快取）是為了不漏
 * 任何一條寫入路徑：`RestaurantSyncService` 的 upsert、`RecalculateRestaurantRatingJob`
 * 觸發的 rating 更新都會經過這裡，不用每個呼叫端各自記得。confidence score 是獨立的表，
 * 見 RestaurantConfidenceScoreObserver。
 */
class RestaurantObserver
{
    public function saved(Restaurant $restaurant): void
    {
        RestaurantCacheInvalidator::invalidate($restaurant->id);
    }

    public function deleted(Restaurant $restaurant): void
    {
        RestaurantCacheInvalidator::invalidate($restaurant->id);
    }
}
