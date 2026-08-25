<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 素食可信度的搜尋入口（總 Prompt 第十一節）。分數本身早就有，但使用者沒辦法
 * 用它來篩或排——「只看有把握是素食的店」是這個產品最核心的需求。
 */
class RestaurantConfidenceSearchTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithScore(string $name, ?int $score): Restaurant
    {
        $restaurant = Restaurant::factory()->create(['name' => $name]);

        if ($score !== null) {
            RestaurantConfidenceScore::create([
                'restaurant_id' => $restaurant->id,
                'score' => $score,
                'calculated_at' => now(),
            ]);
        }

        return $restaurant;
    }

    /** @return list<int> */
    private function search(string $query): array
    {
        $response = $this->getJson('/api/v1/restaurants?'.$query);
        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }

    public function test_confidence_min_keeps_only_restaurants_above_the_threshold(): void
    {
        $high = $this->restaurantWithScore('高分店', 80);
        $low = $this->restaurantWithScore('低分店', 20);
        $none = $this->restaurantWithScore('沒分數店', null);

        $ids = $this->search('confidence_min=50');

        $this->assertSame([$high->id], $ids);
        $this->assertNotContains($low->id, $ids);
        $this->assertNotContains($none->id, $ids, '沒有分數列等同 0 分，不該通過門檻');
    }

    public function test_sort_by_confidence_puts_the_most_verified_first(): void
    {
        $mid = $this->restaurantWithScore('中分店', 50);
        $top = $this->restaurantWithScore('高分店', 90);
        $none = $this->restaurantWithScore('沒分數店', null);

        // 沒有分數列的店要當成 0 分排最後，而不是被 join 整批濾掉。
        $this->assertSame([$top->id, $mid->id, $none->id], $this->search('sort=confidence'));
    }

    public function test_list_response_includes_the_confidence_score(): void
    {
        $restaurant = $this->restaurantWithScore('有分數店', 65);

        $data = $this->getJson('/api/v1/restaurants')->json('data');

        $this->assertSame($restaurant->id, $data[0]['id']);
        $this->assertSame(65, $data[0]['confidence_score']);
    }

    public function test_confidence_min_out_of_range_is_rejected(): void
    {
        $this->getJson('/api/v1/restaurants?confidence_min=200')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
