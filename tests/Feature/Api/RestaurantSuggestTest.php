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
}
