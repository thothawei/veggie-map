<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Restaurant;
use App\Repositories\Search\DidYouMean;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 錯字容錯：「你是不是要找…」（A4）。
 *
 * 這組測試守的是三件事：真的打錯字時給得出建議、正常的查詢不會多跑這一段、
 * 以及**不自動改寫**（只給建議，使用者自己點）。
 */
class DidYouMeanTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(string $name): Restaurant
    {
        $restaurant = Restaurant::factory()->create(['name' => $name]);
        $restaurant->dietTypes()->attach(
            DietType::firstOrCreate(['code' => 'vegan'], ['label' => '全素（Vegan）']),
        );

        return $restaurant;
    }

    /**
     * 這條是整個 A4 最重要的一條：`levenshtein()` 是 byte-based，UTF-8 的中日文
     * 一個字佔 3 bytes，所以它給出的距離跟「差幾個字」無關（素時 vs 素食 給 3、
     * 拉麵 vs 拉面 給 2，兩者其實都只差一個字）。把 distance() 換回內建函式，
     * 這條會紅。
     */
    public function test_cjk_typo_is_measured_by_characters_not_bytes(): void
    {
        $suggestions = DidYouMean::suggest('素時', ['素食', '拉麵', '咖哩']);

        $this->assertSame('素食', $suggestions[0]['term']);
        // 兩個字錯一個 = 0.5。byte-based 的話這個值會是別的數字，門檻也就失去意義。
        $this->assertSame(0.5, $suggestions[0]['score']);
    }

    public function test_japanese_typo_gets_a_suggestion(): void
    {
        // 片假名打錯一個字（ン → ソ，長得很像）。東京有 195 家店，日文使用者
        // 打錯字拿不到任何幫助的話，這個功能對他們等於不存在。
        $suggestions = DidYouMean::suggest('ラーメソ', ['ラーメン', 'カレー']);

        $this->assertSame('ラーメン', $suggestions[0]['term']);
        $this->assertSame(0.75, $suggestions[0]['score']);
    }

    public function test_ascii_typo_gets_a_suggestion(): void
    {
        $suggestions = DidYouMean::suggest('vegitarian', ['vegetarian', 'vegan']);

        $this->assertSame('vegetarian', $suggestions[0]['term']);
        $this->assertSame(0.9, $suggestions[0]['score']);
    }

    public function test_unrelated_words_get_no_suggestion(): void
    {
        // 亂打的字不該硬湊一個建議出來——「你是不是要找 X？」如果 X 毫不相干，
        // 比什麼都不說更讓人困惑。
        $this->assertSame([], DidYouMean::suggest('xyzzy', ['素食', '拉麵', '咖哩']));
    }

    public function test_exact_match_is_not_suggested(): void
    {
        // 「你是不是要找『素食』？」——他打的就是素食。
        $this->assertSame([], DidYouMean::suggest('素食', ['素食']));
    }

    public function test_much_longer_candidates_are_skipped(): void
    {
        // 一個兩字的錯字不可能是想打「時蔬異理義大利麵」。這一刀也是效能防線：
        // 砍掉大部分候選，省下真正貴的距離計算。
        $this->assertSame([], DidYouMean::suggest('素時', ['時蔬異理義大利麵']));
    }

    public function test_api_returns_did_you_mean_only_when_there_are_no_results(): void
    {
        $this->restaurant('素食小館');

        // 「素食」有結果 → 不該有 did_you_mean（正常的查詢不多跑這一段）。
        $withResults = $this->getJson('/api/v1/restaurants?keyword=素食&venue_scope=all');
        $this->assertNotEmpty($withResults->json('data'));
        $this->assertArrayNotHasKey('did_you_mean', $withResults->json('meta'));
    }

    public function test_api_suggests_the_right_word_for_a_typo(): void
    {
        // 「拉面」（簡體寫法）在這個資料集裡搜不到東西，但同義詞表有「拉麵」。
        $this->restaurant('素食小館');

        $response = $this->getJson('/api/v1/restaurants?keyword=拉面&venue_scope=all');

        $this->assertSame([], $response->json('data'));

        $terms = array_column($response->json('meta.did_you_mean') ?? [], 'term');
        $this->assertContains('拉麵', $terms);
    }

    /**
     * **不自動改寫**：回應裡的 data 仍然是 0 筆，did_you_mean 只是建議。
     *
     * 自動改寫會讓使用者不知道自己看的是別的查詢的結果，改錯時也沒有回頭路——
     * Google 那樣做是因為它有信心分數，我們沒有。
     */
    public function test_suggestions_do_not_rewrite_the_query(): void
    {
        $this->restaurant('拉麵屋');

        $response = $this->getJson('/api/v1/restaurants?keyword=拉面&venue_scope=all');

        $this->assertSame([], $response->json('data'), '有建議不代表要替使用者改查詢');
        $this->assertNotEmpty($response->json('meta.did_you_mean'));
    }
}
