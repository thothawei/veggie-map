<?php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * 台灣幾個城市/行政區的粗略經緯度範圍，讓測試資料落在合理的地圖範圍內，
     * 而不是 Faker 預設的全球隨機座標。
     */
    private const CITY_DISTRICTS = [
        ['city' => '台北市', 'district' => '大安區', 'lat' => [25.02, 25.04], 'lng' => [121.53, 121.56]],
        ['city' => '台北市', 'district' => '信義區', 'lat' => [25.02, 25.05], 'lng' => [121.56, 121.58]],
        ['city' => '台中市', 'district' => '西區', 'lat' => [24.13, 24.15], 'lng' => [120.66, 120.68]],
        ['city' => '台中市', 'district' => '北屯區', 'lat' => [24.16, 24.19], 'lng' => [120.68, 120.72]],
        ['city' => '高雄市', 'district' => '苓雅區', 'lat' => [22.61, 22.64], 'lng' => [120.30, 120.32]],
    ];

    public function definition(): array
    {
        $place = fake()->randomElement(self::CITY_DISTRICTS);
        $name = fake()->company().' '.fake()->randomElement(['蔬食', '素食', '全素', '蔬食餐廳']);
        $lat = fake()->randomFloat(7, $place['lat'][0], $place['lat'][1]);
        $lng = fake()->randomFloat(7, $place['lng'][0], $place['lng'][1]);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'description' => fake()->optional()->sentence(12),
            'address' => fake()->streetAddress(),
            'city' => $place['city'],
            'district' => $place['district'],
            'latitude' => $lat,
            'longitude' => $lng,
            // Eloquent 沒有原生 POINT cast，直接用 raw expression 寫入，
            // 跟上面的 latitude/longitude 用同一組數值保持一致。
            'location' => DB::raw("ST_SRID(POINT($lng, $lat), 4326)"),
            'phone' => fake()->optional()->phoneNumber(),
            'website' => fake()->optional()->url(),
            'price_level' => fake()->numberBetween(1, 4),
            'rating' => 0,
            'rating_count' => 0,
            'source' => 'manual',
            'source_id' => null,
            'status' => 'active',
            'is_possible_duplicate' => false,
        ];
    }
}
