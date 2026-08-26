<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Repositories\RestaurantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /**
     * 只測 helper 會過、但 findForDetailBySlug 若改回直接串 slug，HTTP 路徑照樣把
     * 網址寫進 key。這條走真正的請求，拔掉雜湊就會紅。
     */
    public function test_detail_cache_is_stored_under_the_hashed_key_not_the_raw_slug(): void
    {
        Restaurant::factory()->create(['slug' => 'shi-fang-zhai']);

        $this->getJson('/api/v1/restaurants/shi-fang-zhai')->assertOk();

        $this->assertTrue(Cache::has(RestaurantRepository::slugCacheKey('shi-fang-zhai')));
        $this->assertFalse(Cache::has('restaurant:slug:shi-fang-zhai'));
    }

    /**
     * Cache::remember 把 null 當 miss：寫進去的 404 下次 get 還是 null，等於
     * 每次 404 都打 DB、還白寫一個 key。找不到就不寫 cache。
     */
    public function test_unknown_slug_is_not_written_to_cache(): void
    {
        $this->getJson('/api/v1/restaurants/does-not-exist')->assertNotFound();

        $this->assertFalse(Cache::has(RestaurantRepository::slugCacheKey('does-not-exist')));
    }

    /**
     * Invalidator 在 saved 之後才查目前的 slug，改名後只清得到新 key。
     * 舊 slug 那份還會把已不存在的網址吐 600 秒。
     */
    public function test_old_slug_cache_is_forgotten_when_the_slug_changes(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'old-slug', 'name' => '十方齋']);

        $this->getJson('/api/v1/restaurants/old-slug')
            ->assertOk()
            ->assertJsonPath('data.name', '十方齋');

        $restaurant->update(['slug' => 'new-slug']);

        $this->getJson('/api/v1/restaurants/old-slug')->assertNotFound();
        $this->getJson('/api/v1/restaurants/new-slug')
            ->assertOk()
            ->assertJsonPath('data.name', '十方齋');
    }

    /**
     * deleted 當下 DB 列已經沒了。Invalidator 若再 `whereKey()->value('slug')`
     * 會拿到 null，slug 那份快取就清不到——刪掉的店還能用舊網址看 600 秒。
     */
    public function test_slug_cache_is_forgotten_when_the_restaurant_is_deleted(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'shi-fang-zhai']);

        $this->getJson('/api/v1/restaurants/shi-fang-zhai')->assertOk();

        $restaurant->delete();

        $this->getJson('/api/v1/restaurants/shi-fang-zhai')->assertNotFound();
    }
}
