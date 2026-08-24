<?php

namespace App\Jobs;

use App\Models\Restaurant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * `restaurants.rating`／`rating_count` 是快取欄位（見 docs/database.md），不即時彙總
 * `reviews` 算——查詢端只讀這兩欄。這個 Job 負責在 review 異動之後把快取重算好。
 */
class RecalculateRestaurantRatingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $restaurantId)
    {
    }

    public function handle(): void
    {
        $restaurant = Restaurant::find($this->restaurantId);

        if (! $restaurant) {
            return;
        }

        $stats = $restaurant->reviews()
            ->where('status', 'active')
            ->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')
            ->first();

        $restaurant->update([
            'rating' => round((float) ($stats->average_rating ?? 0), 2),
            'rating_count' => (int) $stats->review_count,
        ]);
    }
}
