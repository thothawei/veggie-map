<?php

namespace App\Observers;

use App\Models\RestaurantConfidenceScore;
use App\Services\RestaurantCacheInvalidator;

/**
 * `CalculateRestaurantScoreJob` 只寫這張表，不會觸發 Restaurant model 的 saved
 * event——但 `restaurant:{id}` detail cache 內嵌 confidence_score，兩邊都要能讓
 * 同一個 restaurant 的快取失效，見 RestaurantObserver 的說明與 RestaurantCacheInvalidator。
 */
class RestaurantConfidenceScoreObserver
{
    public function saved(RestaurantConfidenceScore $score): void
    {
        RestaurantCacheInvalidator::invalidate($score->restaurant_id);
    }

    public function deleted(RestaurantConfidenceScore $score): void
    {
        RestaurantCacheInvalidator::invalidate($score->restaurant_id);
    }
}
