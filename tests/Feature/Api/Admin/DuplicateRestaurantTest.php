<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 重複標記的 Admin 審核（總 Prompt 第二十二節）。標記本身從 Phase 8 就有，
 * 但沒有任何地方看得到——標了沒人看等於沒標。
 */
class DuplicateRestaurantTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantAt(string $name, float $lat, float $lng, array $attributes = []): Restaurant
    {
        return Restaurant::factory()->create([
            'name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'location' => DB::raw("ST_SRID(POINT({$lng}, {$lat}), 4326)"),
            'is_possible_duplicate' => true,
            ...$attributes,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_lists_flagged_restaurants_grouped_by_name_and_proximity(): void
    {
        $a = $this->restaurantAt('十方齋', 24.1477, 120.6736);
        $b = $this->restaurantAt('十方齋', 24.1478, 120.6737); // 約 14m
        // 同名但在台北——不是同一家店，不該被歸在同一組。
        $far = $this->restaurantAt('十方齋', 25.0330, 121.5654);

        $groups = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/duplicates')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $groups);

        $pair = collect($groups)->firstWhere('stale', false);
        $this->assertSame([$a->id, $b->id], array_column($pair['restaurants'], 'id'));

        $single = collect($groups)->firstWhere('stale', true);
        $this->assertSame([$far->id], array_column($single['restaurants'], 'id'));
    }

    public function test_keep_clears_the_flag_without_touching_status(): void
    {
        $restaurant = $this->restaurantAt('十方齋', 24.1477, 120.6736);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/duplicate", ['action' => 'keep'])
            ->assertOk()
            ->assertJsonPath('data.is_possible_duplicate', false)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurant->id,
            'is_possible_duplicate' => false,
            'status' => 'active',
        ]);
    }

    public function test_deactivate_takes_it_off_the_map_but_does_not_delete_it(): void
    {
        $restaurant = $this->restaurantAt('十方齋', 24.1477, 120.6736);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/duplicate", ['action' => 'deactivate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        // 判斷錯了還救得回來，reviews／favorites 的外鍵也不會跟著消失。
        $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id, 'status' => 'inactive']);
        $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_can_clear_a_stale_flag_on_an_already_deactivated_restaurant(): void
    {
        // route model binding 只認 active，用它的話這裡會 404——見 Controller 註解。
        $restaurant = $this->restaurantAt('十方齋', 24.1477, 120.6736, ['status' => 'inactive']);

        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/duplicate", ['action' => 'keep'])
            ->assertOk();
    }

    public function test_unknown_action_is_rejected(): void
    {
        $restaurant = $this->restaurantAt('十方齋', 24.1477, 120.6736);

        // 刻意沒有 delete／merge：合併會把一家真實存在的店抹掉，而且不可逆。
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/duplicate", ['action' => 'merge'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_review_duplicates(): void
    {
        $this->restaurantAt('十方齋', 24.1477, 120.6736);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->getJson('/api/v1/admin/duplicates')->assertForbidden();
    }

    public function test_guests_cannot_review_duplicates(): void
    {
        $this->getJson('/api/v1/admin/duplicates')->assertUnauthorized();
    }
}
