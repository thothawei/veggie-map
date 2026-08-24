<?php

namespace App\Console\Commands;

use App\Jobs\CalculateRestaurantScoreJob;
use App\Models\Restaurant;
use Illuminate\Console\Command;

class CalculateRestaurantScores extends Command
{
    protected $signature = 'restaurants:calculate-scores';

    protected $description = '批次重算所有餐廳的 confidence score（見 CalculateRestaurantScoreJob）';

    public function handle(): int
    {
        $count = 0;

        Restaurant::query()->select('id')->chunkById(200, function ($restaurants) use (&$count) {
            foreach ($restaurants as $restaurant) {
                CalculateRestaurantScoreJob::dispatchSync($restaurant->id);
                $count++;
            }
        });

        $this->info("Calculated confidence scores for {$count} restaurants.");

        return self::SUCCESS;
    }
}
