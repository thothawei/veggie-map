<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 常駐 quick filter 帶「按下去會剩幾家」（B4）。
 *
 * 這組測試守的是：只算 B3 定案的三個常駐 quick filter（店家範圍／營業中／
 * 可信度最高一級），不是全部篩選欄位；而且每個候選值的數字要是「其他條件
 * 不變，只換這一個維度」算出來的，不是全表數量。
 */
class RestaurantFacetsTest extends TestCase
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

    private function friendlyRestaurant(string $name): Restaurant
    {
        $restaurant = Restaurant::factory()->create(['name' => $name]);
        $restaurant->dietTypes()->attach(
            DietType::firstOrCreate(['code' => 'vegan_friendly'], ['label' => '全素友善']),
        );

        return $restaurant;
    }

    public function test_venue_scope_facet_counts_all_three_candidates(): void
    {
        $this->exclusiveRestaurant('純素店');
        $this->friendlyRestaurant('友善店');

        $data = $this->getJson('/api/v1/restaurants/facets')->json('data');

        $byValue = collect($data['venue_scope'])->keyBy('value');

        $this->assertSame(1, $byValue['exclusive']['count']);
        $this->assertSame(1, $byValue['friendly']['count']);
        $this->assertSame(2, $byValue['all']['count']);
    }

    public function test_open_now_facet_only_counts_the_true_candidate(): void
    {
        $this->exclusiveRestaurant('店家');

        $data = $this->getJson('/api/v1/restaurants/facets')->json('data');

        $this->assertSame(true, $data['open_now']['value']);
        $this->assertArrayHasKey('count', $data['open_now']);
        // 沒有可解析營業時間的店不算營業中——見 applyOpenNow 的既有規則，
        // 這裡只是確認 facets 走的是同一套邏輯，不是另外寫一份。
        $this->assertSame(0, $data['open_now']['count']);
    }

    public function test_confidence_facet_only_uses_the_highest_tier(): void
    {
        $restaurant = Restaurant::factory()->create();
        RestaurantConfidenceScore::create([
            'restaurant_id' => $restaurant->id,
            'score' => 70,
            'calculated_at' => now(),
        ]);

        $data = $this->getJson('/api/v1/restaurants/facets')->json('data');

        // config('vegetarian.confidence_filters') 目前是 [30 => 有查證, 60 => 高度可信]，
        // quick chip 只挑最後一級。
        $this->assertSame(60, $data['confidence_min']['value']);
        $this->assertSame('高度可信', $data['confidence_min']['label']);
        $this->assertSame(1, $data['confidence_min']['count']);
    }

    /**
     * 每個候選值是「其他條件不變」算出來的：先用 diet 篩選收窄到只剩一家，
     * 三個 venue_scope 候選值的數字要反映那個收窄後的結果，不是全表數量。
     */
    public function test_facets_respect_other_active_filters(): void
    {
        $this->exclusiveRestaurant('目標店');
        $this->exclusiveRestaurant('目標店2');

        $data = $this->getJson('/api/v1/restaurants/facets?'.http_build_query(['keyword' => '目標店2']))
            ->json('data');

        $byValue = collect($data['venue_scope'])->keyBy('value');
        $this->assertSame(1, $byValue['exclusive']['count']);
        $this->assertSame(1, $byValue['all']['count']);
    }

    /** 反向驗證用的判準：換掉篩選條件，數字要跟著變，不能是寫死的常數。 */
    public function test_counts_change_when_underlying_data_changes(): void
    {
        $first = $this->getJson('/api/v1/restaurants/facets')->json('data');
        $this->assertSame(0, collect($first['venue_scope'])->firstWhere('value', 'exclusive')['count']);

        $this->exclusiveRestaurant('新開的店');

        $second = $this->getJson('/api/v1/restaurants/facets')->json('data');
        $this->assertSame(1, collect($second['venue_scope'])->firstWhere('value', 'exclusive')['count']);
    }

    public function test_facets_does_not_paginate_or_return_restaurant_data(): void
    {
        $this->exclusiveRestaurant('店家');

        $response = $this->getJson('/api/v1/restaurants/facets');

        $response->assertOk();
        $this->assertArrayNotHasKey('meta', $response->json());
        $this->assertArrayHasKey('venue_scope', $response->json('data'));
    }

    public function test_invalid_filters_still_return_422(): void
    {
        // facets 吃跟 index() 同一份 SearchRestaurantRequest，驗證規則不該因為
        // 換了端點就變鬆——網址被改壞時要回 422，不是安靜地忽略壞掉的條件。
        $this->getJson('/api/v1/restaurants/facets?price_level=99')->assertStatus(422);
    }
}
