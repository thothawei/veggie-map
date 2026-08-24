<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 見總體規劃第十六／十七節：/restaurants 的 search／detail 都要走 Redis cache，
 * 修改後要清快取，不能整個 Cache::flush()。這裡不只驗證「回應內容對」，是直接數
 * DB query 次數證明真的沒有再打 DB，不然 cache 可以整個沒接上、資料還是對，測試卻是綠的
 * ——判準是 pitfall-negative-assertion-wrong-path：把快取拔掉這個測試會不會紅。
 */
class RestaurantCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_search_hits_cache_not_the_database(): void
    {
        Restaurant::factory()->count(3)->create();

        $this->getJson('/api/v1/restaurants')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/v1/restaurants')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, '第二次相同查詢應該完全從 cache 拿，不打 DB。');
    }

    public function test_different_filters_are_not_served_from_the_same_cache_entry(): void
    {
        Restaurant::factory()->create(['city' => '台北市']);
        Restaurant::factory()->create(['city' => '台中市']);

        $taipei = $this->getJson('/api/v1/restaurants?city=台北市')->assertOk()->json('data');
        $taichung = $this->getJson('/api/v1/restaurants?city=台中市')->assertOk()->json('data');

        $this->assertCount(1, $taipei);
        $this->assertCount(1, $taichung);
        $this->assertNotSame($taipei[0]['id'], $taichung[0]['id']);
    }

    public function test_repeated_detail_request_hits_cache_not_the_database(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")->assertOk();

        DB::enableQueryLog();
        $this->getJson("/api/v1/restaurants/{$restaurant->id}")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
    }

    public function test_updating_a_restaurant_invalidates_its_detail_cache(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => '舊名字']);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertJsonPath('data.name', '舊名字');

        $restaurant->update(['name' => '新名字']);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertJsonPath('data.name', '新名字');
    }

    public function test_updating_confidence_score_invalidates_detail_cache_even_though_it_is_a_different_table(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertJsonPath('data.confidence_score', null);

        // 模擬 CalculateRestaurantScoreJob：只寫 restaurant_confidence_scores，
        // 不會觸發 Restaurant model 的 saved event。
        RestaurantConfidenceScore::updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            ['score' => 42, 'calculated_at' => now()],
        );

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertJsonPath('data.confidence_score', 42);
    }

    public function test_exceeding_the_rate_limit_returns_429(): void
    {
        RateLimiter::for('api', fn () => Limit::perMinute(2));

        $this->getJson('/api/v1/diets')->assertOk();
        $this->getJson('/api/v1/diets')->assertOk();
        $this->getJson('/api/v1/diets')->assertStatus(429);
    }
}
