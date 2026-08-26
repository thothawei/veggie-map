<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\RestaurantSlugAlias;
use App\Repositories\RestaurantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 回寫拼音 slug 之後，舊網址仍然要打得開（見 restaurant_slug_aliases migration）。
 */
class RestaurantSlugAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_slug_still_resolves_to_the_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'qing-xin-shu-shi',
            'name' => '清心蔬食',
            'status' => 'active',
        ]);
        RestaurantSlugAlias::create(['restaurant_id' => $restaurant->id, 'slug' => 'osm-node-123']);

        $this->getJson('/api/v1/restaurants/osm-node-123')
            ->assertOk()
            ->assertJsonPath('data.id', $restaurant->id)
            // 回的是現行 slug，前端據此把網址換成正牌那個。
            ->assertJsonPath('data.slug', 'qing-xin-shu-shi');
    }

    public function test_alias_of_an_inactive_restaurant_is_not_reachable(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shi-fang-zhai', 'status' => 'inactive']);
        RestaurantSlugAlias::create(['restaurant_id' => $restaurant->id, 'slug' => 'osm-node-9']);

        $this->getJson('/api/v1/restaurants/osm-node-9')->assertNotFound();
    }

    public function test_alias_lookup_is_invalidated_when_the_restaurant_changes(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slug' => 'qing-xin-shu-shi',
            'name' => '清心蔬食',
            'status' => 'active',
        ]);
        RestaurantSlugAlias::create(['restaurant_id' => $restaurant->id, 'slug' => 'osm-node-123']);

        $this->getJson('/api/v1/restaurants/osm-node-123')
            ->assertOk()
            ->assertJsonPath('data.name', '清心蔬食');

        $restaurant->update(['name' => '清心蔬食二號店']);

        $this->getJson('/api/v1/restaurants/osm-node-123')
            ->assertOk()
            ->assertJsonPath('data.name', '清心蔬食二號店');
    }

    /**
     * alias 是 FK cascade 刪的，deleted 時已經查不到——快取若不在 deleting 清，
     * 已刪除的店會靠舊網址再活 600 秒。
     */
    public function test_alias_cache_is_forgotten_when_the_restaurant_is_deleted(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'qing-xin-shu-shi', 'status' => 'active']);
        RestaurantSlugAlias::create(['restaurant_id' => $restaurant->id, 'slug' => 'osm-node-123']);

        $this->getJson('/api/v1/restaurants/osm-node-123')->assertOk();
        $this->assertTrue(Cache::has(RestaurantRepository::slugCacheKey('osm-node-123')));

        $restaurant->delete();

        $this->assertFalse(Cache::has(RestaurantRepository::slugCacheKey('osm-node-123')));
        $this->getJson('/api/v1/restaurants/osm-node-123')->assertNotFound();
    }

    public function test_unknown_alias_is_still_a_404(): void
    {
        $this->getJson('/api/v1/restaurants/never-existed')->assertNotFound();
    }
}
