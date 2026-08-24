<?php

namespace Database\Seeders;

use App\Models\DietType;
use App\Models\Feature;
use App\Models\Restaurant;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Seeder;

class RestaurantSeeder extends Seeder
{
    /**
     * 開發用 demo 資料，讓 `php artisan migrate --seed` 之後地圖跟搜尋 API 有東西可以打，
     * 不是正式資料——正式資料走 Phase 8 的 `restaurants:sync`。
     */
    public function run(): void
    {
        $dietTypeIds = DietType::pluck('id', 'code');
        $featureIds = Feature::pluck('id', 'code');

        Restaurant::factory()
            ->count(20)
            ->create()
            ->each(function (Restaurant $restaurant) use ($dietTypeIds, $featureIds) {
                $restaurant->dietTypes()->attach(
                    $dietTypeIds->shuffle()->take(fake()->numberBetween(1, 2))->values()
                );
                $restaurant->features()->attach(
                    $featureIds->shuffle()->take(fake()->numberBetween(0, 3))->values()
                );
                $restaurant->menuItems()->createMany(
                    MenuItemFactory::new()
                        ->count(fake()->numberBetween(3, 8))
                        ->make(['restaurant_id' => $restaurant->id])
                        ->toArray()
                );
            });
    }
}
