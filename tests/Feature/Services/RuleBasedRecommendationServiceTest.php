<?php

namespace Tests\Feature\Services;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use App\Repositories\RestaurantRepository;
use App\Services\Recommendation\RuleBasedRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `RuleBasedRecommendationService` 的關聯（dietTypes／features／confidenceScore）是真的
 * Eloquent 關聯，測分數邏輯需要真的資料庫，跟這個專案既有的 Job test 放在 tests/Feature/
 * 而非 tests/Unit/ 是同一個理由（見 tests/Feature/Jobs/）。
 */
class RuleBasedRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN_LAT = 25.0330;

    private const ORIGIN_LNG = 121.5645;

    public function test_closer_higher_rated_more_verified_restaurant_ranks_first(): void
    {
        // 近、高評分、有 confidence score、掛了 features——每個分量都該贏。
        $strong = Restaurant::factory()->create([
            'latitude' => 25.0332,
            'longitude' => 121.5647,
            'location' => DB::raw('ST_SRID(POINT(121.5647, 25.0332), 4326)'),
            'rating' => 5.0,
            'rating_count' => 100,
            'created_at' => now(),
        ]);
        RestaurantConfidenceScore::factory()->for($strong, 'restaurant')->create(['score' => 90]);
        $strong->features()->attach(Feature::factory()->count(3)->create());

        // 遠、低評分、沒有 confidence score、沒有 features——每個分量都該輸。
        $weak = Restaurant::factory()->create([
            'latitude' => 25.08,
            'longitude' => 121.60,
            'location' => DB::raw('ST_SRID(POINT(121.60, 25.08), 4326)'),
            'rating' => 1.0,
            'rating_count' => 1,
            'created_at' => now()->subDays(200),
        ]);

        $candidates = app(RestaurantRepository::class)
            ->candidatesForRecommendation(self::ORIGIN_LAT, self::ORIGIN_LNG, 10, 30);

        $ranked = app(RuleBasedRecommendationService::class)->rank($candidates);

        $this->assertSame($strong->id, $ranked->first()->id);
        $this->assertSame($weak->id, $ranked->last()->id);
        $this->assertGreaterThan($ranked->last()->recommendation_score, $ranked->first()->recommendation_score);
    }

    public function test_score_is_bounded_between_zero_and_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $lat = self::ORIGIN_LAT + (mt_rand(-50, 50) / 10000);
            $lng = self::ORIGIN_LNG + (mt_rand(-50, 50) / 10000);

            Restaurant::factory()->create([
                'latitude' => $lat,
                'longitude' => $lng,
                'location' => DB::raw("ST_SRID(POINT({$lng}, {$lat}), 4326)"),
            ]);
        }

        $candidates = app(RestaurantRepository::class)
            ->candidatesForRecommendation(self::ORIGIN_LAT, self::ORIGIN_LNG, 10, 30);

        $ranked = app(RuleBasedRecommendationService::class)->rank($candidates);

        $this->assertCount(5, $ranked);
        foreach ($ranked as $restaurant) {
            $this->assertGreaterThanOrEqual(0, $restaurant->recommendation_score);
            $this->assertLessThanOrEqual(1, $restaurant->recommendation_score);
        }
    }

    public function test_empty_candidates_returns_empty_collection(): void
    {
        $ranked = app(RuleBasedRecommendationService::class)->rank(collect());

        $this->assertTrue($ranked->isEmpty());
    }
}
