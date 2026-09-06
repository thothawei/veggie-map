<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 零結果時的「放寬哪一個條件會有幾家」（A3）。
 *
 * 零結果是搜尋體驗最貴的一刻——使用者當下的問題不是「沒有店」而是「我做錯了什麼」。
 * 這組測試守的是：答案要算得準（按鈕說 N 家，按下去就真的有 N 家），
 * 而且只在真的 0 筆時才付出那幾次 COUNT 的成本。
 */
class RestaurantRelaxationTest extends TestCase
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

    public function test_no_relaxations_key_when_there_are_results(): void
    {
        $this->exclusiveRestaurant('有結果的店');

        $meta = $this->getJson('/api/v1/restaurants?venue_scope=exclusive')->json('meta');

        // 有結果時連算都不該算——每一項是一次 COUNT(*)。
        $this->assertArrayNotHasKey('relaxations', $meta);
    }

    /**
     * 規劃寫的驗收情境：開著「營業中」搜到 0 筆時，畫面要出現
     * 「不限營業中（N 家）」，而且按下去真的有 N 家。
     */
    public function test_open_now_relaxation_count_matches_what_you_actually_get(): void
    {
        // 三家店都沒有營業時間資料，所以 open_now=1 一定是 0 筆
        // （沒有可解析營業時間的店不算營業中，見 applyOpenNow）。
        $this->exclusiveRestaurant('甲素食');
        $this->exclusiveRestaurant('乙素食');
        $this->exclusiveRestaurant('丙素食');

        $response = $this->getJson('/api/v1/restaurants?venue_scope=exclusive&open_now=1');
        $response->assertOk();
        $this->assertSame([], $response->json('data'));

        $relaxations = collect($response->json('meta.relaxations'));
        $openNow = $relaxations->firstWhere('param', 'open_now');

        $this->assertNotNull($openNow, 'open_now 開著卻沒有建議放寬它');
        $this->assertSame('不限營業中', $openNow['label']);
        $this->assertSame(3, $openNow['count']);

        // 按下去＝把 open_now 拿掉。說 3 家就要真的是 3 家，否則這個按鈕比不做更糟。
        $actual = $this->getJson('/api/v1/restaurants?venue_scope=exclusive')->json('data');
        $this->assertCount($openNow['count'], $actual);
    }

    /**
     * venue_scope 的放寬**不是移除參數**而是改成 all：移除的話前端會退回自己的
     * 預設值（純素食店），使用者會覺得按了沒反應。
     */
    public function test_venue_scope_relaxation_says_which_value_to_switch_to(): void
    {
        $this->friendlyRestaurant('友善食堂');

        $response = $this->getJson('/api/v1/restaurants?venue_scope=exclusive');
        $this->assertSame([], $response->json('data'));

        $scope = collect($response->json('meta.relaxations'))->firstWhere('param', 'venue_scope');

        $this->assertNotNull($scope);
        $this->assertSame('all', $scope['value'], 'venue_scope 要改成 all，不是移除');
        $this->assertSame(1, $scope['count']);

        $actual = $this->getJson('/api/v1/restaurants?venue_scope=all')->json('data');
        $this->assertCount(1, $actual);
    }

    public function test_confidence_relaxation_counts_correctly(): void
    {
        $restaurant = $this->exclusiveRestaurant('低分店');
        RestaurantConfidenceScore::factory()->create([
            'restaurant_id' => $restaurant->id,
            'score' => 10,
        ]);

        $response = $this->getJson('/api/v1/restaurants?venue_scope=exclusive&confidence_min=60');
        $this->assertSame([], $response->json('data'));

        $confidence = collect($response->json('meta.relaxations'))->firstWhere('param', 'confidence_min');

        $this->assertNotNull($confidence);
        $this->assertNull($confidence['value'], 'confidence_min 是移除，不是改值');
        $this->assertSame(1, $confidence['count']);
    }

    public function test_only_suggests_conditions_the_user_actually_set(): void
    {
        // 一家都沒有：放寬任何條件都還是 0 筆，所以整個 key 不出現——
        // 「不限營業中（0 家）」是一個沒有用的按鈕。
        $response = $this->getJson('/api/v1/restaurants?venue_scope=exclusive&open_now=1');

        $this->assertSame([], $response->json('data'));
        $this->assertArrayNotHasKey('relaxations', $response->json('meta'));
    }

    public function test_at_most_three_suggestions(): void
    {
        $this->friendlyRestaurant('友善食堂');

        // 四個條件全開，但最多只回三項——四個按鈕就不像「下一步」
        // 而像另一組篩選器了。
        $response = $this->getJson(
            '/api/v1/restaurants?venue_scope=exclusive&open_now=1&confidence_min=60'
            .'&bbox=20.0000,118.0000,26.0000,123.0000',
        );

        $this->assertSame([], $response->json('data'));
        $this->assertLessThanOrEqual(3, count($response->json('meta.relaxations') ?? []));
    }
}
