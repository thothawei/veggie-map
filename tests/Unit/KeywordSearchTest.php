<?php

namespace Tests\Unit;

use App\Repositories\Search\KeywordSearch;
use Tests\TestCase;

/**
 * 斷詞規則。用 TestCase（不是純 PHPUnit TestCase）因為 terms() 讀 config。
 */
class KeywordSearchTest extends TestCase
{
    public function test_expand_adds_synonyms_and_keeps_the_original_first(): void
    {
        $groups = KeywordSearch::expand(['珍珠奶茶']);

        $this->assertCount(1, $groups);
        // 原詞永遠在第一個：展開是為了多找到東西，不能把使用者真正打的詞弄丟。
        $this->assertSame('珍珠奶茶', $groups[0][0]);
        $this->assertContains('手搖', $groups[0]);
        $this->assertContains('飲料', $groups[0]);
    }

    public function test_expand_is_bidirectional(): void
    {
        // 搜「手搖」的人一樣想看到珍珠奶茶店——詞表是群組不是單向映射。
        $this->assertContains('珍珠奶茶', KeywordSearch::expand(['手搖'])[0]);
    }

    public function test_expand_ignores_case_for_latin_terms(): void
    {
        $this->assertContains('全素', KeywordSearch::expand(['VEGAN'])[0]);
        $this->assertContains('全素', KeywordSearch::expand(['vegan'])[0]);
    }

    public function test_expand_leaves_unknown_terms_alone(): void
    {
        // 詞表沒收的詞就是它自己，不要為了「有展開」硬塞近似詞。
        $this->assertSame([['臭豆腐']], KeywordSearch::expand(['臭豆腐']));
    }

    public function test_expand_keeps_one_group_per_term_so_multi_word_stays_and(): void
    {
        $groups = KeywordSearch::expand(['台中', '拉麵']);

        // 攤平成一維會讓「台中 拉麵」從 AND 變成 OR，回傳所有台中的店。
        $this->assertCount(2, $groups);
        $this->assertSame('台中', $groups[0][0]);
        $this->assertContains('ramen', $groups[1]);
    }

    public function test_expand_respects_the_variant_cap_without_dropping_the_original(): void
    {
        config(['veggiemap.search.max_variants' => 2]);

        $groups = KeywordSearch::expand(['珍珠奶茶']);

        $this->assertCount(2, $groups[0]);
        $this->assertSame('珍珠奶茶', $groups[0][0]);
    }

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
        [$sql, $bindings] = KeywordSearch::relevanceExpression([['拉麵'], ['台中']]);

        // binding 數量跟 `?` 對不上時，MySQL 會把整串參數往前錯位——症狀是
        // 排序看起來隨機，不是報錯，所以值得一條測試守著。
        $this->assertSame(substr_count($sql, '?'), count($bindings));
    }

    public function test_relevance_bindings_still_line_up_after_synonym_expansion(): void
    {
        // 展開後每個群組會產出「變體數 × 條件數」個 `?`，這是最容易把 binding
        // 順序寫錯的地方——錯位不會報錯，只會讓排序看起來隨機。
        [$sql, $bindings] = KeywordSearch::relevanceExpression(KeywordSearch::expand(['珍珠奶茶', '台中']));

        $this->assertSame(substr_count($sql, '?'), count($bindings));
    }
}
