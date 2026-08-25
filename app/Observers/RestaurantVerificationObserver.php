<?php

namespace App\Observers;

use App\Jobs\CalculateRestaurantScoreJob;
use App\Models\RestaurantVerification;

/**
 * 可信度分數只由 restaurant_verifications 彙總而來，所以重算掛在這張表的 model event 上，
 * 不要求每個寫入路徑（OSM 同步、Admin 手動驗證、回報核准連動）各自記得 dispatch——
 * 跟 RestaurantObserver 同一個理由：漏掉一條路徑就會有分數永遠不更新的店。
 */
class RestaurantVerificationObserver
{
    public function saved(RestaurantVerification $verification): void
    {
        CalculateRestaurantScoreJob::dispatch($verification->restaurant_id);
    }

    public function deleted(RestaurantVerification $verification): void
    {
        CalculateRestaurantScoreJob::dispatch($verification->restaurant_id);
    }
}
