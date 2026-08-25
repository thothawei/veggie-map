<?php

namespace Tests\Feature\Api;

use App\Models\Favorite;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_favorite_requires_authentication(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->postJson("/api/v1/restaurants/{$restaurant->id}/favorite")->assertStatus(401);
    }

    public function test_favorite_then_list_then_unfavorite(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)
            ->postJson("/api/v1/restaurants/{$restaurant->id}/favorite")
            ->assertStatus(201);

        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'restaurant_id' => $restaurant->id]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/me/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $restaurant->id);

        $this->withHeaders($headers)
            ->deleteJson("/api/v1/restaurants/{$restaurant->id}/favorite")
            ->assertOk();

        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'restaurant_id' => $restaurant->id]);
    }

    public function test_favoriting_twice_is_idempotent(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson("/api/v1/restaurants/{$restaurant->id}/favorite")->assertStatus(201);
        $this->withHeaders($headers)->postJson("/api/v1/restaurants/{$restaurant->id}/favorite")->assertStatus(201);

        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_unfavoriting_a_non_favorited_restaurant_is_a_no_op_success(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/v1/restaurants/{$restaurant->id}/favorite")
            ->assertOk();
    }

    public function test_cannot_favorite_an_inactive_restaurant(): void
    {
        $user = User::factory()->create();
        $restaurant = Restaurant::factory()->create(['status' => 'pending']);

        $this->withHeaders($this->authHeaders($user))
            ->postJson("/api/v1/restaurants/{$restaurant->id}/favorite")
            ->assertStatus(404);
    }

    public function test_favorites_list_excludes_inactive_restaurants(): void
    {
        $user = User::factory()->create();
        $active = Restaurant::factory()->create(['status' => 'active']);
        $inactive = Restaurant::factory()->create(['status' => 'pending']);
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson("/api/v1/restaurants/{$active->id}/favorite")->assertStatus(201);

        Favorite::create([
            'user_id' => $user->id,
            'restaurant_id' => $inactive->id,
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/me/favorites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }
}
