<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RecalculateRestaurantRatingJob;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateRestaurantRatingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_averages_only_active_reviews(): void
    {
        $restaurant = Restaurant::factory()->create(['rating' => 0, 'rating_count' => 0]);

        Review::factory()->create(['restaurant_id' => $restaurant->id, 'user_id' => User::factory(), 'rating' => 4, 'status' => 'active']);
        Review::factory()->create(['restaurant_id' => $restaurant->id, 'user_id' => User::factory(), 'rating' => 2, 'status' => 'active']);
        Review::factory()->create(['restaurant_id' => $restaurant->id, 'user_id' => User::factory(), 'rating' => 5, 'status' => 'hidden']);

        RecalculateRestaurantRatingJob::dispatchSync($restaurant->id);

        $restaurant->refresh();
        $this->assertSame(2, $restaurant->rating_count);
        $this->assertEquals(3.0, (float) $restaurant->rating);
    }

    public function test_zero_active_reviews_resets_to_zero(): void
    {
        $restaurant = Restaurant::factory()->create(['rating' => 4.5, 'rating_count' => 3]);

        RecalculateRestaurantRatingJob::dispatchSync($restaurant->id);

        $restaurant->refresh();
        $this->assertSame(0, $restaurant->rating_count);
        $this->assertEquals(0.0, (float) $restaurant->rating);
    }

    public function test_submitting_a_review_via_the_api_updates_restaurant_rating(): void
    {
        $restaurant = Restaurant::factory()->create(['rating' => 0, 'rating_count' => 0]);
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reviews", ['rating' => 4])
            ->assertStatus(201);

        $restaurant->refresh();
        $this->assertSame(1, $restaurant->rating_count);
        $this->assertEquals(4.0, (float) $restaurant->rating);
    }
}
