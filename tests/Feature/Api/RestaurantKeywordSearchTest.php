<?php

namespace Tests\Feature\Api;

use App\Models\Feature;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 關鍵字搜尋（總 Prompt 第八節）。
 *
 * 這組測試守的是兩件事：命中範圍（搜「拉麵」要找得到有拉麵的店，即使店名沒有）
 * 與排序（店名命中要排在地址剛好有這兩個字的前面）。
 */
class RestaurantKeywordSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> */
    private function search(string $query): array
    {
        $response = $this->getJson('/api/v1/restaurants?'.$query);
        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }

    public function test_keyword_matches_menu_item_names(): void
    {
        $withRamen = Restaurant::factory()->create(['name' => '綠光食堂']);
        MenuItem::factory()->create([
            'restaurant_id' => $withRamen->id,
            'name' => '味噌拉麵',
            'diet_type' => 'vegan',
        ]);

        Restaurant::factory()->create(['name' => '陽光蔬食']);

        $this->assertSame([$withRamen->id], $this->search('keyword=拉麵'));
    }

    public function test_keyword_matches_cuisine_labels(): void
    {
        $japanese = Restaurant::factory()->create(['name' => '一號店']);
        $feature = Feature::factory()->create(['code' => 'japanese', 'label' => '日式料理']);
        $japanese->features()->attach($feature->id);

        Restaurant::factory()->create(['name' => '二號店']);

        $this->assertSame([$japanese->id], $this->search('keyword=日式'));
    }

    public function test_name_match_outranks_address_match(): void
    {
        // 地址剛好有「公益」兩個字的店，不應該排在店名就叫「公益」的前面。
        $addressOnly = Restaurant::factory()->create([
            'name' => '甲蔬食',
            'address' => '台中市西區公益路 100 號',
        ]);
        $byName = Restaurant::factory()->create([
            'name' => '公益蔬食',
            'address' => '台中市北區文心路 1 號',
        ]);

        $this->assertSame([$byName->id, $addressOnly->id], $this->search('keyword=公益'));
    }

    public function test_exact_name_outranks_partial_name(): void
    {
        $partial = Restaurant::factory()->create(['name' => '十方齋素食館']);
        $exact = Restaurant::factory()->create(['name' => '十方齋']);

        $this->assertSame([$exact->id, $partial->id], $this->search('keyword=十方齋'));
    }

    public function test_multiple_terms_are_combined_with_and(): void
    {
        $both = Restaurant::factory()->create(['name' => '拉麵屋', 'city' => '台中市']);
        Restaurant::factory()->create(['name' => '拉麵屋', 'city' => '台北市']);
        Restaurant::factory()->create(['name' => '蔬食館', 'city' => '台中市']);

        $this->assertSame([$both->id], $this->search('keyword='.urlencode('台中 拉麵')));
    }

    public function test_like_wildcards_in_the_keyword_are_escaped(): void
    {
        // 不跳脫的話 "%" 會變成萬用字元，這個查詢會撈回全部餐廳。
        Restaurant::factory()->create(['name' => '甲蔬食']);
        Restaurant::factory()->create(['name' => '乙蔬食']);

        $this->assertSame([], $this->search('keyword='.urlencode('%')));
    }

    public function test_relevance_sort_requires_a_keyword(): void
    {
        $this->getJson('/api/v1/restaurants?sort=relevance')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_explicit_sort_still_wins_over_relevance_default(): void
    {
        $older = Restaurant::factory()->create(['name' => '蔬食一號']);
        $newer = Restaurant::factory()->create(['name' => '蔬食二號']);

        // 預設會是 relevance；明確要 newest 就照做。
        $this->assertSame([$newer->id, $older->id], $this->search('keyword=蔬食&sort=newest'));
    }

    public function test_keyword_search_still_respects_other_filters(): void
    {
        $match = Restaurant::factory()->create(['name' => '蔬食一號', 'price_level' => 1]);
        Restaurant::factory()->create(['name' => '蔬食二號', 'price_level' => 4]);

        $this->assertSame([$match->id], $this->search('keyword=蔬食&price_level=1'));
    }
}
