<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\RestaurantConfidenceScore;
use App\Support\VerificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 可信度的三段標籤（B8）。
 *
 * 守的是兩件事：畫面拿到的是標籤而不是裸分數，以及標籤的門檻跟篩選晶片的門檻
 * 是同一組數字——後者漂掉的話，使用者按「有查證」篩出來的店，卡片會寫「待確認」。
 */
class ConfidenceLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_level_thresholds_match_the_filter_chips(): void
    {
        /*
         * config 裡有兩份門檻：confidence_filters（FilterDrawer 的晶片）與
         * confidence_levels（卡片標籤）。它們必須是同一組數字，否則
         * 「按了『有查證』，結果每張卡片都寫『待確認』」。
         *
         * 這條測試不比對 label 文字（晶片是動詞「有查證」、標籤可以有自己的語氣），
         * 只比對門檻數值本身。
         */
        $filterValues = array_column(config('vegetarian.confidence_filters'), 'value');

        // levels 比 filters 多一段最低的（min=0），那一段沒有對應的晶片——
        // 「待確認」不是使用者會想篩的條件。
        $levelMins = array_values(array_filter(
            array_column(config('vegetarian.confidence_levels'), 'min'),
            fn (int $min): bool => $min > 0,
        ));

        sort($filterValues);
        sort($levelMins);

        $this->assertSame($filterValues, $levelMins, '卡片標籤與篩選晶片的可信度門檻漂掉了');
    }

    public function test_level_maps_each_band(): void
    {
        $this->assertSame('high', VerificationCatalog::level(80)['code']);
        $this->assertSame('high', VerificationCatalog::level(60)['code']);
        $this->assertSame('verified', VerificationCatalog::level(59)['code']);
        $this->assertSame('verified', VerificationCatalog::level(30)['code']);
        $this->assertSame('unverified', VerificationCatalog::level(29)['code']);

        // 2026-09-06 實測：1148 家有分數的店全部落在 5 或 10 分，也就是全部都是
        // 「待確認」。裸分數在現況下幾乎沒有區辨力，卻會被讀成「這家店 5 分很爛」。
        $this->assertSame('unverified', VerificationCatalog::level(10)['code']);
        $this->assertSame('unverified', VerificationCatalog::level(5)['code']);
    }

    public function test_missing_score_is_the_same_as_zero(): void
    {
        // 沒有分數列跟 0 分在使用者眼裡是同一件事（沒有人查證過），分開講沒有意義。
        $this->assertSame(
            VerificationCatalog::level(0),
            VerificationCatalog::level(null),
        );
    }

    /**
     * 一家還沒有任何驗證紀錄的店（沒有 restaurant_confidence_scores 那一列）
     * **仍然要顯示「待確認」**，而不是什麼都不說——「還沒有人查證過」正是這個
     * 標籤要講的事，沉默反而讓使用者不知道能不能相信它。
     *
     * 這條測試釘住的是一個反直覺的 Laravel 行為：`whenLoaded()` 在「關聯載入了
     * 但沒有那一列」時直接回 null、不執行 callback，所以這裡必須用
     * `when(relationLoaded(...))`。2026-09-06 實測 1167 家 active 店裡有 19 家
     * 沒有分數列，用 whenLoaded 的話它們全部不顯示標籤。
     */
    public function test_restaurant_without_a_score_row_still_gets_a_label(): void
    {
        Restaurant::factory()->create();

        $data = $this->getJson('/api/v1/restaurants?venue_scope=all')->json('data.0');

        $this->assertNull($data['confidence_score']);
        $this->assertSame('unverified', $data['confidence_level']['code']);
    }

    public function test_list_returns_the_label_not_just_the_number(): void
    {
        $restaurant = Restaurant::factory()->create();
        RestaurantConfidenceScore::factory()->create([
            'restaurant_id' => $restaurant->id,
            'score' => 10,
        ]);

        $data = $this->getJson('/api/v1/restaurants?venue_scope=all')->json('data.0');

        $this->assertSame('unverified', $data['confidence_level']['code']);
        $this->assertSame('素食資訊待確認', $data['confidence_level']['label']);
        // 分數本身保留給 API 使用端與排序，只是畫面不印它。
        $this->assertSame(10, $data['confidence_score']);
    }
}
