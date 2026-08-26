<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use App\Models\RestaurantVerification;
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

    /**
     * cursor 分頁的排序欄位是 SELECT 出來的計算欄位（`confidence`／`relevance`），
     * 不是資料表欄位。這種情況最容易出現「翻頁時同一家店出現兩次或整個消失」——
     * 尤其是分數大量相同時（OSM 匯入的店全部都是 10 分）。
     */
    public function test_cursor_pagination_under_confidence_sort_never_repeats_or_skips(): void
    {
        $expected = [];

        foreach (range(1, 12) as $i) {
            // 前六家同分，逼出「同分時靠什麼決定順序」那條路徑。
            $expected[] = $this->restaurantWithScore("店 {$i}", $i <= 6 ? 40 : 10 + $i)->id;
        }

        $seen = [];
        $cursor = null;

        do {
            $url = '/api/v1/restaurants?sort=confidence&per_page=5'.($cursor ? "&cursor={$cursor}" : '');
            $response = $this->getJson($url)->assertOk();

            foreach ($response->json('data') as $row) {
                $seen[] = $row['id'];
            }

            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertSame(count($seen), count(array_unique($seen)), '有店家被重複列出');
        sort($seen);
        sort($expected);
        $this->assertSame($expected, $seen, '有店家在翻頁時被漏掉');
    }

    /**
     * 只有一個數字的話，使用者沒辦法判斷要不要相信它——「管理員已查證」跟
     * 「OSM 標示」是很不一樣的證據。
     */
    public function test_detail_explains_where_the_confidence_score_comes_from(): void
    {
        $restaurant = Restaurant::factory()->create();

        RestaurantVerification::create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'external_source',
            'score' => 10,
            'verified_at' => now(),
        ]);
        RestaurantVerification::create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'admin_verified',
            'score' => 30,
            'verified_at' => now(),
        ]);

        $breakdown = $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->json('data.confidence_breakdown');

        // 分數高的排前面。
        $this->assertSame('admin_verified', $breakdown[0]['code']);
        $this->assertSame(30, $breakdown[0]['score']);
        $this->assertSame('管理員已查證', $breakdown[0]['label']);
        $this->assertCount(2, $breakdown);
    }

    /**
     * 同一類型多筆是重複證據（每日 sync 各寫一筆 external_source）。明細必須跟
     * `CalculateRestaurantScoreJob` 用同一套規則取最高分——不然畫面上加起來會跟
     * 總分對不上，那比不顯示明細更傷信任。
     */
    public function test_repeated_verifications_of_the_same_type_count_once(): void
    {
        $restaurant = Restaurant::factory()->create();

        foreach (range(1, 3) as $ignored) {
            RestaurantVerification::create([
                'restaurant_id' => $restaurant->id,
                'verification_type' => 'external_source',
                'score' => 10,
                'verified_at' => now(),
            ]);
        }

        $breakdown = $this->getJson("/api/v1/restaurants/{$restaurant->id}")->json('data.confidence_breakdown');

        $this->assertCount(1, $breakdown);
        $this->assertSame(10, $breakdown[0]['score']);
    }

    public function test_expired_verifications_are_left_out(): void
    {
        $restaurant = Restaurant::factory()->create();

        RestaurantVerification::create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'user_report',
            'score' => 10,
            'verified_at' => now()->subYears(3),
            // 「三年前有人回報過」不該一直撐著分數。
            'expires_at' => now()->subYear(),
        ]);

        $breakdown = $this->getJson("/api/v1/restaurants/{$restaurant->id}")->json('data.confidence_breakdown');

        $this->assertSame([], $breakdown);
    }

    public function test_list_does_not_carry_the_breakdown(): void
    {
        $restaurant = Restaurant::factory()->create();
        RestaurantVerification::create([
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'admin_verified',
            'score' => 30,
            'verified_at' => now(),
        ]);

        // 列表不顯示明細，多撈一張表沒有意義。
        $this->assertArrayNotHasKey(
            'confidence_breakdown',
            $this->getJson('/api/v1/restaurants')->json('data.0'),
        );
    }
}
