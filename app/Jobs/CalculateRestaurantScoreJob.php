<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * `restaurant_confidence_scores` 由這個 Job 整批重算後 upsert，查詢端只讀這張表，
 * 不即時彙總 `restaurant_verifications`（見 docs/database.md）。依類型各取最高分再加總
 * （索引註解寫的是「依餐廳＋類型彙總」）：同一類型多筆是重複證據，例如每日 sync 各寫
 * 一筆 `external_source`，不能每筆 +10 把可信度灌到 100。封頂在 100。
 */
class CalculateRestaurantScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $restaurantId) {}

    public function handle(): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        if (! $restaurant) {
            return;
        }

        $totalScore = (int) $restaurant->verifications()
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            ->groupBy('verification_type')
            ->sum(fn ($rows) => (int) $rows->max('score'));

        RestaurantConfidenceScore::updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            ['score' => min(100, $totalScore), 'calculated_at' => now()],
        );
    }
}
