<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CalculateRestaurantScoreJob;
use App\Models\Restaurant;
use App\Models\RestaurantVerification;
use App\Services\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateRestaurantScoreJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sums_non_expired_verification_scores(): void
    {
        $restaurant = Restaurant::factory()->create();

        RestaurantVerification::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'menu_verified',
            'score' => 20,
            'expires_at' => null,
        ]);
        RestaurantVerification::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'user_report',
            'score' => 15,
            'expires_at' => now()->addDay(),
        ]);
        RestaurantVerification::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'admin_verified',
            'score' => 50,
            'expires_at' => now()->subDay(), // 已過期，不應計入
        ]);

        CalculateRestaurantScoreJob::dispatchSync($restaurant->id);

        $this->assertDatabaseHas('restaurant_confidence_scores', [
            'restaurant_id' => $restaurant->id,
            'score' => 35,
        ]);
    }

    public function test_score_is_capped_at_100(): void
    {
        $restaurant = Restaurant::factory()->create();

        RestaurantVerification::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'menu_verified',
            'score' => 60,
        ]);
        RestaurantVerification::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'admin_verified',
            'score' => 60,
        ]);

        CalculateRestaurantScoreJob::dispatchSync($restaurant->id);

        $this->assertDatabaseHas('restaurant_confidence_scores', [
            'restaurant_id' => $restaurant->id,
            'score' => 100,
        ]);
    }

    public function test_verification_service_looks_up_score_from_config(): void
    {
        $restaurant = Restaurant::factory()->create();

        $verification = app(VerificationService::class)
            ->record($restaurant, 'admin_verified');

        $this->assertSame(config('vegetarian.verification_weights.admin_verified'), $verification->score);
    }

    public function test_duplicate_verification_types_count_once(): void
    {
        $restaurant = Restaurant::factory()->create();

        RestaurantVerification::factory()->count(5)->create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'external_source',
            'score' => 10,
            'expires_at' => null,
        ]);

        CalculateRestaurantScoreJob::dispatchSync($restaurant->id);

        $this->assertDatabaseHas('restaurant_confidence_scores', [
            'restaurant_id' => $restaurant->id,
            'score' => 10,
        ]);
    }
}
