<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantSlugAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillRestaurantSlugsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_touch_anything(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'osm-node-1']);

        $this->artisan('restaurants:backfill-slugs')->assertSuccessful();

        $this->assertSame('osm-node-1', $restaurant->fresh()->slug);
        $this->assertSame(0, RestaurantSlugAlias::count());
    }

    public function test_force_rewrites_the_slug_and_keeps_the_old_one_as_an_alias(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => '清心蔬食',
            'slug' => 'osm-node-1',
            'status' => 'active',
        ]);

        $this->artisan('restaurants:backfill-slugs --force')->assertSuccessful();

        $this->assertSame('qing-xin-shu-shi', $restaurant->fresh()->slug);
        $this->assertDatabaseHas('restaurant_slug_aliases', [
            'restaurant_id' => $restaurant->id,
            'slug' => 'osm-node-1',
        ]);

        // 這是整支指令存在的理由：舊網址不能因為換 slug 就死掉。
        $this->getJson('/api/v1/restaurants/osm-node-1')
            ->assertOk()
            ->assertJsonPath('data.id', $restaurant->id);
    }

    /**
     * 同名店在同一輪都還沒寫進 DB，只查 DB 會算出同一個 slug 然後撞 unique index。
     */
    public function test_same_name_restaurants_do_not_collide_within_one_run(): void
    {
        Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'osm-node-1']);
        Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'osm-node-2']);

        $this->artisan('restaurants:backfill-slugs --force')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['qing-xin-shu-shi', 'qing-xin-shu-shi-2'],
            Restaurant::pluck('slug')->all(),
        );
    }

    public function test_running_twice_does_not_add_a_numeric_suffix_to_itself(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'osm-node-1']);

        $this->artisan('restaurants:backfill-slugs --force')->assertSuccessful();
        $this->artisan('restaurants:backfill-slugs --force')->assertSuccessful();

        $this->assertSame('qing-xin-shu-shi', $restaurant->fresh()->slug);
        $this->assertSame(1, RestaurantSlugAlias::count());
    }

    public function test_already_correct_slug_is_left_alone(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'qing-xin-shu-shi']);

        $this->artisan('restaurants:backfill-slugs --force')->assertSuccessful();

        $this->assertSame('qing-xin-shu-shi', $restaurant->fresh()->slug);
        $this->assertSame(0, RestaurantSlugAlias::count());
    }

    /**
     * dry-run 不寫 DB，所以「同一輪已經配掉的 slug」只存在記憶體裡。少了那道
     * 檢查，兩家同名店在報表上都會顯示成 qing-xin-shu-shi——而這份報表正是
     * 使用者用來決定要不要 --force 的依據，不能說謊。
     */
    public function test_dry_run_report_shows_the_suffix_for_same_name_restaurants(): void
    {
        Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'osm-node-1']);
        Restaurant::factory()->create(['name' => '清心蔬食', 'slug' => 'osm-node-2']);

        $this->artisan('restaurants:backfill-slugs')
            ->expectsOutputToContain('osm-node-1 → qing-xin-shu-shi')
            ->expectsOutputToContain('osm-node-2 → qing-xin-shu-shi-2')
            ->assertSuccessful();
    }
}
