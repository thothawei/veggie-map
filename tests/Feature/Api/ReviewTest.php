<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_index_is_public_and_only_shows_active_reviews(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();

        Review::factory()->create(['restaurant_id' => $restaurant->id, 'user_id' => $user->id, 'status' => 'active']);
        Review::factory()->create(['restaurant_id' => $restaurant->id, 'user_id' => $user->id, 'status' => 'hidden']);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_requires_authentication(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->postJson("/api/v1/restaurants/{$restaurant->id}/reviews", ['rating' => 5])
            ->assertStatus(401);
    }

    public function test_store_validates_rating_range(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();

        $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reviews", ['rating' => 9])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_resubmitting_a_review_hides_the_old_one_instead_of_duplicating(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reviews", ['rating' => 3, 'comment' => 'first'])
            ->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reviews", ['rating' => 5, 'comment' => 'second'])
            ->assertStatus(201);

        $this->assertDatabaseCount('reviews', 2);
        $this->assertSame(1, Review::where('restaurant_id', $restaurant->id)->where('status', 'active')->count());

        $response = $this->getJson("/api/v1/restaurants/{$restaurant->id}/reviews")->json();
        $this->assertCount(1, $response['data']);
        $this->assertSame('second', $response['data'][0]['comment']);
    }
}
