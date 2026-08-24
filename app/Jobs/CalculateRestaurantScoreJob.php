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
 * 不即時彙總 `restaurant_verifications`（見 docs/database.md）。加總未過期驗證的
 * `score`（各筆分數在寫入時已經套過 config/vegetarian.php 的權重，見 VerificationService），
 * 封頂在 100。
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
            ->sum('score');

        RestaurantConfidenceScore::updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            ['score' => min(100, $totalScore), 'calculated_at' => now()],
        );
    }
}
