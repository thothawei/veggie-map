<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Repositories\RestaurantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/restaurants/{slug}`（總 Prompt 第二十六節）。id 仍然可用——既有的分享連結
 * 不能因為改路由就全部失效。
 */
class RestaurantSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_can_be_fetched_by_slug(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shi-fang-zhai']);

        $this->getJson('/api/v1/restaurants/shi-fang-zhai')
            ->assertOk()
            ->assertJsonPath('data.id', $restaurant->id)
            ->assertJsonPath('data.slug', 'shi-fang-zhai');
    }

    public function test_detail_by_id_still_works(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shi-fang-zhai']);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $restaurant->id);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/v1/restaurants/does-not-exist')->assertNotFound();
    }

    public function test_inactive_restaurant_is_not_reachable_by_slug(): void
    {
        Restaurant::factory()->create(['slug' => 'hidden-one', 'status' => 'pending']);

        $this->getJson('/api/v1/restaurants/hidden-one')->assertNotFound();
    }

    public function test_slug_lookup_is_cached_but_invalidated_on_update(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shi-fang-zhai', 'name' => '十方齋']);

        $this->getJson('/api/v1/restaurants/shi-fang-zhai')->assertJsonPath('data.name', '十方齋');

        $restaurant->update(['name' => '十方齋二店']);

        // 只清 id 那份快取的話，這裡會拿到 600 秒的舊名字——而 slug 正是前端在用
        // 的那條路徑，等於快取失效對使用者完全沒生效。
        $this->getJson('/api/v1/restaurants/shi-fang-zhai')->assertJsonPath('data.name', '十方齋二店');
    }

    /**
     * slug 直接來自網址。`slug` 欄位是 varchar(255)，比它長的不可能存在——
     * 提早擋掉，才不會拿一個 4KB 的字串去查 DB、還順便寫一個 4KB 的 cache key。
     */
    public function test_absurdly_long_slug_is_rejected_without_touching_the_database(): void
    {
        DB::enableQueryLog();

        $this->getJson('/api/v1/restaurants/'.str_repeat('a', 4000))->assertNotFound();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            array_filter($queries, fn (array $q) => str_contains($q['query'], 'restaurants')),
            '過長的 slug 不該打到 DB',
        );
    }

    public function test_slug_cache_key_is_hashed_so_the_url_cannot_shape_redis_keys(): void
    {
        $weird = "slug with spaces\nand newline";

        $this->assertSame(
            'restaurant:slug:'.md5($weird),
            RestaurantRepository::slugCacheKey($weird),
        );
    }
}
