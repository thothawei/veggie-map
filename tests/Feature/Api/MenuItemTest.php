<?php

namespace Tests\Feature\Api;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\DietCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuItemTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_admin_can_create_a_menu_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/menu-items", [
                'name' => '白飯',
                'price' => 30,
                'diet_type' => 'vegan',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', '白飯')
            ->assertJsonPath('data.diet_type', 'vegan')
            ->assertJsonPath('data.diet_label', DietCatalog::menuItemDietLabel('vegan'))
            ->assertJsonPath('data.is_available', true);

        $this->assertDatabaseHas('menu_items', [
            'restaurant_id' => $restaurant->id,
            'name' => '白飯',
            'diet_type' => 'vegan',
        ]);
    }

    public function test_illegal_diet_type_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/menu-items", [
                'name' => '神秘料理',
                'diet_type' => 'pescatarian',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_diet_type_removed_from_config_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        config([
            'diet.menu_item_diets' => array_values(array_filter(
                config('diet.menu_item_diets'),
                fn (array $item) => $item['code'] !== 'unknown',
            )),
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/menu-items", [
                'name' => '未標示的菜',
                'diet_type' => 'unknown',
            ])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_create_a_menu_item(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($user))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/menu-items", [
                'name' => '白飯',
                'diet_type' => 'vegan',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('menu_items', 0);
    }

    public function test_guest_cannot_create_a_menu_item(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->postJson("/api/v1/admin/restaurants/{$restaurant->id}/menu-items", [
            'name' => '白飯',
            'diet_type' => 'vegan',
        ])->assertStatus(401);
    }

    public function test_factory_diet_type_always_comes_from_config(): void
    {
        config([
            'diet.menu_item_diets' => [
                ['code' => 'vegan', 'label' => '全素'],
            ],
        ]);

        $item = MenuItem::factory()->make();

        $this->assertSame('vegan', $item->diet_type);
    }
}
