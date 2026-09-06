<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Restaurant;
use App\Models\SearchMiss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 零結果查詢紀錄（A8）。
 *
 * 這組測試守的是：只在真的 0 筆時才寫、有結果時完全不碰這張表、不記任何
 * 身分資訊（這是產品訊號不是使用者追蹤）、而且分得出「單純這個詞查不到」
 * 跟「詞查得到但篩選太嚴」。
 */
class SearchMissTest extends TestCase
{
    use RefreshDatabase;

    private function exclusiveRestaurant(string $name): Restaurant
    {
        $restaurant = Restaurant::factory()->create(['name' => $name]);
        $restaurant->dietTypes()->attach(
            DietType::firstOrCreate(['code' => 'vegan'], ['label' => '全素（Vegan）']),
        );

        return $restaurant;
    }

    public function test_zero_results_writes_a_miss_row(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在的店')->assertOk();

        $this->assertDatabaseCount('search_misses', 1);
        $this->assertDatabaseHas('search_misses', [
            'keyword' => '不存在的店',
            'result_count' => 0,
            'had_filters' => false,
        ]);
    }

    /** 反向驗證：有結果的查詢不該碰這張表，跑它就是純粹的浪費。 */
    public function test_results_found_does_not_write_a_miss_row(): void
    {
        $this->exclusiveRestaurant('有結果的店');

        $this->getJson('/api/v1/restaurants?keyword=有結果')->assertOk();

        $this->assertDatabaseCount('search_misses', 0);
    }

    public function test_normalized_column_collapses_whitespace_and_case(): void
    {
        $this->getJson('/api/v1/restaurants?'.http_build_query(['keyword' => '  Vegitarian  ']))->assertOk();

        $miss = SearchMiss::sole();
        $this->assertSame('vegitarian', $miss->normalized);
    }

    /**
     * 同一個詞（正規化後）打錯大小寫或多空白，排行榜要能算成同一筆——
     * 這是 search:misses 指令排序的依據。
     */
    public function test_same_keyword_with_different_casing_normalizes_to_the_same_value(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=RAMEN')->assertOk();
        $this->getJson('/api/v1/restaurants?keyword=ramen')->assertOk();

        $this->assertSame(2, SearchMiss::where('normalized', 'ramen')->count());
    }

    public function test_no_keyword_still_gets_recorded_but_without_a_normalized_value(): void
    {
        // 篩選把純瀏覽篩成 0 筆——沒有關鍵字，但一樣值得記（只是對「該加哪些
        // 同義詞」這個問題沒有幫助）。
        $this->getJson('/api/v1/restaurants?confidence_min=100')->assertOk();

        $miss = SearchMiss::sole();
        $this->assertNull($miss->keyword);
        $this->assertNull($miss->normalized);
        $this->assertTrue($miss->had_filters);
    }

    public function test_had_filters_is_false_for_a_plain_keyword_miss(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在')->assertOk();

        $this->assertFalse(SearchMiss::sole()->had_filters);
    }

    public function test_had_filters_is_true_when_a_filter_is_also_set(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&open_now=1')->assertOk();

        $this->assertTrue(SearchMiss::sole()->had_filters);
    }

    /** venue_scope 的預設值是每個請求都會帶的隱性預設，不算使用者主動開的篩選。 */
    public function test_had_filters_is_false_when_venue_scope_is_just_the_default(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&venue_scope=exclusive')->assertOk();

        $this->assertFalse(SearchMiss::sole()->had_filters);
    }

    public function test_had_filters_is_true_when_venue_scope_is_explicitly_widened(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&venue_scope=all')->assertOk();

        $this->assertTrue(SearchMiss::sole()->had_filters);
    }

    /** 不記任何身分或位置資訊——這是產品訊號，不是使用者追蹤。 */
    public function test_never_records_identity_or_location_fields(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&bbox=24.9613,121.4570,25.2130,121.6663')
            ->assertOk();

        $columns = array_keys(SearchMiss::sole()->getAttributes());

        foreach (['ip', 'ip_address', 'user_id', 'latitude', 'longitude'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "search_misses 不該有 {$forbidden} 欄位");
        }
    }

    /**
     * B4 量體評估：只知道「有沒有開篩選」（`had_filters`）回答不出「是哪一個」，
     * 累積再多真實流量也決定不了 B3 常駐 quick filter 該是哪三個。這裡記的是
     * 開了哪些鍵，不是只有一個布林值。
     */
    public function test_active_filters_records_which_keys_were_open(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&open_now=1&confidence_min=60')->assertOk();

        $this->assertEqualsCanonicalizing(
            ['open_now', 'confidence_min'],
            SearchMiss::sole()->active_filters,
        );
    }

    public function test_active_filters_is_null_when_no_filters_are_open(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在')->assertOk();

        $this->assertNull(SearchMiss::sole()->active_filters);
    }

    /** venue_scope 等於預設值不算「開了」，這裡跟 had_filters 是同一個判準。 */
    public function test_active_filters_excludes_venue_scope_at_its_default_value(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&venue_scope=exclusive')->assertOk();

        $this->assertNull(SearchMiss::sole()->active_filters);
    }

    /** 只記鍵名不記值——跟這張表一貫的原則一樣，這是產品訊號不是使用者追蹤。 */
    public function test_active_filters_stores_only_key_names_not_values(): void
    {
        $this->getJson('/api/v1/restaurants?keyword=不存在&confidence_min=60')->assertOk();

        $miss = SearchMiss::sole();
        $this->assertSame(['confidence_min'], $miss->active_filters);
        $this->assertStringNotContainsString('60', json_encode($miss->active_filters));
    }
}
