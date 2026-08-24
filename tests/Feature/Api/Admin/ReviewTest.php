<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Restaurant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_index_requires_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/admin/reviews')
            ->assertStatus(403);
    }

    public function test_admin_can_list_all_reviews_regardless_of_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        Review::factory()->create(['restaurant_id' => $restaurant->id, 'status' => 'active']);
        Review::factory()->create(['restaurant_id' => $restaurant->id, 'status' => 'hidden']);

        $this->withHeaders($this->headers($admin))
            ->getJson('/api/v1/admin/reviews')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_hiding_a_review_recalculates_restaurant_rating(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create(['rating' => 5, 'rating_count' => 1]);
        $review = Review::factory()->create(['restaurant_id' => $restaurant->id, 'rating' => 5, 'status' => 'active']);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reviews/{$review->id}/hide")
            ->assertOk()
            ->assertJsonPath('data.id', $review->id);

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'status' => 'hidden']);

        $restaurant->refresh();
        $this->assertSame(0, $restaurant->rating_count);
    }

    public function test_hiding_an_already_hidden_review_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $review = Review::factory()->create(['status' => 'hidden']);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reviews/{$review->id}/hide")
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_hide_a_review(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $review = Review::factory()->create(['status' => 'active']);

        $this->withHeaders($this->headers($user))
            ->postJson("/api/v1/admin/reviews/{$review->id}/hide")
            ->assertStatus(403);
    }
}
