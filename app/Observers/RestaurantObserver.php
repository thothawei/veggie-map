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
        // saved 時 getOriginal() 還沒 sync，slug 改過的話這裡仍是舊值。
        RestaurantCacheInvalidator::invalidate(
            $restaurant->id,
            self::stringOrNull($restaurant->getOriginal('slug')),
            $restaurant->slug,
        );
    }

    /**
     * alias 是 FK cascade 刪掉的，`deleted` 時已經查不到了。趁列還在先清一次，
     * 否則舊網址會繼續吐 600 秒已刪除的店。
     *
     * 不用實例屬性把 slug 從 deleting 帶到 deleted：observer 是每次事件由容器
     * 重新解析的，兩個 callback 不是同一個實例。
     */
    public function deleting(Restaurant $restaurant): void
    {
        RestaurantCacheInvalidator::invalidate($restaurant->id, $restaurant->slug);
    }

    public function deleted(Restaurant $restaurant): void
    {
        // 列已經不在 DB，slug 只能從記憶體裡的 model 拿。
        RestaurantCacheInvalidator::invalidate(
            $restaurant->id,
            $restaurant->slug,
            self::stringOrNull($restaurant->getOriginal('slug')),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
