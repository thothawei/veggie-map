<?php

namespace Tests\Feature\Api;

use App\Models\Feature;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 搜尋建議（自動完成）。回三種型別：店名、料理種類、行政區。
 */
class RestaurantSuggestTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_restaurant_names_ordered_by_relevance(): void
    {
        Restaurant::factory()->create(['name' => '十方齋素食館']);
        $exact = Restaurant::factory()->create(['name' => '十方齋']);

        $data = $this->getJson('/api/v1/restaurants/suggest?q=十方齋')
            ->assertOk()
            ->json('data');

        $this->assertSame($exact->id, $data['restaurants'][0]['id']);
        $this->assertCount(2, $data['restaurants']);
    }

    public function test_suggests_cuisine_categories_by_chinese_label(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => '一號店']);
        $feature = Feature::factory()->create(['code' => 'japanese', 'label' => '日式料理']);
        $restaurant->features()->attach($feature->id);

        $data = $this->getJson('/api/v1/restaurants/suggest?q=日式')->json('data');

        $this->assertSame([['code' => 'japanese', 'label' => '日式料理']], $data['cuisines']);
    }

    public function test_does_not_suggest_cuisines_with_no_restaurants(): void
    {
        // 建議一個點下去 0 筆的分類等於騙使用者。
        Feature::factory()->create(['code' => 'japanese', 'label' => '日式料理']);

        $data = $this->getJson('/api/v1/restaurants/suggest?q=日式')->json('data');

        $this->assertSame([], $data['cuisines']);
    }

    public function test_suggests_districts_that_actually_have_data(): void
    {
        Restaurant::factory()->create(['city' => '台中市', 'district' => '西區', 'name' => '甲店']);
        Restaurant::factory()->create(['city' => '台中市', 'district' => '西區', 'name' => '乙店']);

        $data = $this->getJson('/api/v1/restaurants/suggest?q=西區')->json('data');

        // 同一個區只出現一次，不是每家店各一列。
        $this->assertSame([['city' => '台中市', 'district' => '西區']], $data['districts']);
    }

    public function test_city_narrows_the_suggestions(): void
    {
        $taichung = Restaurant::factory()->create(['name' => '蔬食小館', 'city' => '台中市']);
        Restaurant::factory()->create(['name' => '蔬食小館', 'city' => '台北市']);

        $data = $this->getJson('/api/v1/restaurants/suggest?q=蔬食&city='.urlencode('台中市'))->json('data');

        $this->assertSame([$taichung->id], array_column($data['restaurants'], 'id'));
    }

    public function test_query_is_required(): void
    {
        $this->getJson('/api/v1/restaurants/suggest')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_suggest_route_is_not_swallowed_by_the_detail_route(): void
    {
        // /restaurants/{restaurant} 若排在前面，這條會被當成 id=suggest 的餐廳而 404。
        $this->getJson('/api/v1/restaurants/suggest?q=abc')->assertOk();
    }

    public function test_inactive_restaurants_are_not_suggested(): void
    {
        Restaurant::factory()->create(['name' => '未上架蔬食', 'status' => 'pending']);

        $data = $this->getJson('/api/v1/restaurants/suggest?q=未上架')->json('data');

        $this->assertSame([], $data['restaurants']);
    }

    /**
     * OSM 匯入的餐廳有大量 city／district 是空的，光靠那兩欄，建議清單會出現
     * 「五筆都叫素食、看不出差別」（2026-08-26 瀏覽器實測）。所以 address 也要回。
     */
    public function test_suggestion_carries_enough_to_tell_two_same_named_shops_apart(): void
    {
        Restaurant::factory()->create([
            'name' => '素食',
            'slug' => 'su-shi-a',
            'address' => '台北市中正區羅斯福路 1 號',
            'city' => '',
            'district' => '',
        ]);

        $suggestion = $this->getJson('/api/v1/restaurants/suggest?q=素食')->json('data.restaurants.0');

        $this->assertSame('台北市中正區羅斯福路 1 號', $suggestion['address']);
        $this->assertSame('su-shi-a', $suggestion['slug']);
        $this->assertNull($suggestion['city'], '空字串要正規化成 null，前端才能用 ?? 退回地址');
    }

    public function test_suggestions_that_can_say_where_they_are_come_first(): void
    {
        // 兩筆相關性一樣，但其中一筆連地址與城市都是空的（OSM 有一批這種資料），
        // 在清單上只會顯示「素食 地址未提供」，對使用者沒有幫助。
        $nameless = Restaurant::factory()->create([
            'name' => '素食',
            'address' => '',
            'city' => '',
            'district' => '',
        ]);
        $located = Restaurant::factory()->create([
            'name' => '素食',
            'address' => '台北市中正區羅斯福路 1 號',
            'city' => '台北市',
        ]);

        $ids = array_column(
            $this->getJson('/api/v1/restaurants/suggest?q=素食')->json('data.restaurants'),
            'id',
        );

        $this->assertSame([$located->id, $nameless->id], $ids);
    }
}
