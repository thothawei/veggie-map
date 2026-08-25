<?php

namespace Tests\Unit;

use App\Repositories\Search\KeywordSearch;
use Tests\TestCase;

/**
 * 斷詞規則。用 TestCase（不是純 PHPUnit TestCase）因為 terms() 讀 config。
 */
class KeywordSearchTest extends TestCase
{
    public function test_splits_on_whitespace_and_cjk_punctuation(): void
    {
        $this->assertSame(['台中', '拉麵'], KeywordSearch::terms('台中 拉麵'));
        $this->assertSame(['台中', '拉麵'], KeywordSearch::terms('台中、拉麵'));
        $this->assertSame(['台中', '拉麵'], KeywordSearch::terms('台中，拉麵'));
    }

    public function test_drops_noise_terms_shorter_than_the_latin_threshold(): void
    {
        $this->assertSame(['台中', '拉麵'], KeywordSearch::terms('台中 拉麵 a'));
    }

    public function test_single_cjk_character_is_a_valid_term(): void
    {
        $this->assertSame(['麵'], KeywordSearch::terms('麵'));
    }

    /**
     * 全部被門檻砍光時退回原字串。不退回的話，使用者打一個英文字母會拿到
     * 「全部餐廳」——看起來像搜尋壞掉，比拿到 0 筆更難理解。
     */
    public function test_falls_back_to_the_raw_keyword_when_every_term_is_dropped(): void
    {
        $this->assertSame(['a'], KeywordSearch::terms('a'));
        $this->assertSame(['%'], KeywordSearch::terms('%'));
    }

    public function test_caps_the_number_of_terms(): void
    {
        config(['veggiemap.search.keyword_max_terms' => 2]);

        $this->assertSame(['台中', '拉麵'], KeywordSearch::terms('台中 拉麵 素食 便當'));
    }

    public function test_empty_keyword_yields_no_terms(): void
    {
        $this->assertSame([], KeywordSearch::terms('   '));
    }

    public function test_relevance_expression_binding_count_matches_placeholders(): void
    {
        [$sql, $bindings] = KeywordSearch::relevanceExpression(['拉麵', '台中']);

        // binding 數量跟 `?` 對不上時，MySQL 會把整串參數往前錯位——症狀是
        // 排序看起來隨機，不是報錯，所以值得一條測試守著。
        $this->assertSame(substr_count($sql, '?'), count($bindings));
    }
}
