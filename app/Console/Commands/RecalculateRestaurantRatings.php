<?php

namespace App\Console\Commands;

use App\Jobs\RecalculateRestaurantRatingJob;
use App\Models\Restaurant;
use Illuminate\Console\Command;

class RecalculateRestaurantRatings extends Command
{
    protected $signature = 'restaurants:recalculate-ratings';

    protected $description = '批次重算所有餐廳的 rating／rating_count 快取欄位（見 RecalculateRestaurantRatingJob）';

    public function handle(): int
    {
        $count = 0;

        Restaurant::query()->select('id')->chunkById(200, function ($restaurants) use (&$count) {
            foreach ($restaurants as $restaurant) {
                RecalculateRestaurantRatingJob::dispatchSync($restaurant->id);
                $count++;
            }
        });

        $this->info("Recalculated ratings for {$count} restaurants.");

        return self::SUCCESS;
    }
}
