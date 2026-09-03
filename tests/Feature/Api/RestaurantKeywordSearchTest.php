<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Feature;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 關鍵字搜尋（總 Prompt 第八節）。
 *
 * 這組測試守的是兩件事：命中範圍（搜「拉麵」要找得到有拉麵的店，即使店名沒有）
 * 與排序（店名命中要排在地址剛好有這兩個字的前面）。
 */
class RestaurantKeywordSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> */
    private function search(string $query): array
    {
        $response = $this->getJson('/api/v1/restaurants?'.$query);
        $response->assertOk();

        return array_column($response->json('data'), 'id');
    }

    public function test_synonym_finds_a_shop_tagged_with_a_different_wording(): void
    {
        // 實測催生這條測試的案例：「珍珠奶茶」原本搜出 0 筆，但資料庫裡有 22 家
        // 標成手搖飲的店——使用者打得出來的詞跟資料裡的詞對不上。
        $bubbleTea = Restaurant::factory()->create(['name' => '綠意茶飲']);
        $feature = Feature::factory()->create(['code' => 'bubble_tea', 'label' => '手搖飲']);
        $bubbleTea->features()->attach($feature);

        Restaurant::factory()->create(['name' => '陽光蔬食']);

        $this->assertSame([$bubbleTea->id], $this->search('keyword=珍珠奶茶'));
        // 雙向：搜「手搖」的人一樣要找得到。
        $this->assertSame([$bubbleTea->id], $this->search('keyword=手搖'));
    }

    public function test_synonym_expansion_does_not_turn_multi_word_and_into_or(): void
    {
        // city 要明寫：factory 會隨機挑城市，不指定的話「台北那家」有機會落在
        // 台中市，於是它也命中「台中」——測試會紅在資料而不是行為上。
        $taichungTea = Restaurant::factory()->create([
            'name' => '台中珍奶店', 'city' => '台中市', 'district' => '西區',
        ]);
        $taipeiTea = Restaurant::factory()->create([
            'name' => '台北珍奶店', 'city' => '台北市', 'district' => '大安區',
        ]);
        $feature = Feature::factory()->create(['code' => 'bubble_tea', 'label' => '手搖飲']);
        $taichungTea->features()->attach($feature);
        $taipeiTea->features()->attach($feature);

        // 兩個詞都要命中。展開若把變體攤平成一維，這裡會連台北那家一起回傳。
        $this->assertSame([$taichungTea->id], $this->search('keyword=台中 珍珠奶茶'));
    }

    public function test_match_reasons_use_the_expanded_terms(): void
    {
        // 命中原因若只比對原詞，這家店會顯示成「不知道為什麼中的」。
        $shop = Restaurant::factory()->create(['name' => '綠意茶飲']);
        MenuItem::factory()->create([
            'restaurant_id' => $shop->id,
            'name' => '黑糖奶茶',
            'diet_type' => 'vegetarian',
        ]);

        $response = $this->getJson('/api/v1/restaurants?keyword=珍珠奶茶');
        $response->assertOk();

        $this->assertSame(['黑糖奶茶'], $response->json('data.0.matched_menu_items'));
    }

    public function test_keyword_matches_diet_types(): void
    {
        // 「蛋奶素」以前一筆都回不來——標籤明明就在資料裡。
        $ovoLacto = Restaurant::factory()->create(['name' => '安心食堂']);
        $ovoLacto->dietTypes()->attach(
            DietType::factory()->create(['code' => 'ovo_lacto', 'label' => '蛋奶素（Ovo-Lacto）']),
        );

        Restaurant::factory()->create(['name' => '別家店']);

        $this->assertSame([$ovoLacto->id], $this->search('keyword=蛋奶素&venue_scope=all'));
        // code 也要收：API 使用者與網址分享會帶 code。
        $this->assertSame([$ovoLacto->id], $this->search('keyword=ovo_lacto&venue_scope=all'));
    }

    public function test_diet_match_does_not_outrank_a_name_match(): void
    {
        /*
         * 每一家店都有 diet 標籤，所以命中 diet 幾乎不帶資訊。它的分數刻意是最低的
         * 一級——不然搜「vegan」時，排在最前面的會是一堆跟關鍵字無關、只是剛好
         * 標了全素的店，而店名真的叫 Vegan 的反而被埋在後面。
         */
        $byDietOnly = Restaurant::factory()->create(['name' => '綠意廚房']);
        $byName = Restaurant::factory()->create(['name' => 'Vegan 8ablish']);
        $vegan = DietType::factory()->create(['code' => 'vegan', 'label' => '全素（Vegan）']);
        $byDietOnly->dietTypes()->attach($vegan);
        $byName->dietTypes()->attach($vegan);

        $this->assertSame(
            [$byName->id, $byDietOnly->id],
            $this->search('keyword=vegan&venue_scope=all&sort=relevance'),
        );
    }

    public function test_keyword_matches_menu_item_names(): void
    {
        $withRamen = Restaurant::factory()->create(['name' => '綠光食堂']);
        MenuItem::factory()->create([
            'restaurant_id' => $withRamen->id,
            'name' => '味噌拉麵',
            'diet_type' => 'vegan',
        ]);

        Restaurant::factory()->create(['name' => '陽光蔬食']);

        $this->assertSame([$withRamen->id], $this->search('keyword=拉麵'));
    }

    public function test_keyword_matches_cuisine_labels(): void
    {
        $japanese = Restaurant::factory()->create(['name' => '一號店']);
        $feature = Feature::factory()->create(['code' => 'japanese', 'label' => '日式料理']);
        $japanese->features()->attach($feature->id);

        Restaurant::factory()->create(['name' => '二號店']);

        $this->assertSame([$japanese->id], $this->search('keyword=日式'));
    }

    public function test_matched_reasons_cover_more_than_just_menu_items(): void
    {
        // 命中原因只說得出菜色一種的話，命中料理種類／地區／描述／飲食類型的店
        // 會顯示成「不知道為什麼中的」——這條測試把六種來源各造一家店，逐一釘住。
        $byName = Restaurant::factory()->create(['name' => '拉麵屋', 'description' => null]);

        $byCuisine = Restaurant::factory()->create(['name' => '一號店', 'description' => null]);
        // 'ramen' 在 config/cuisine.php 已經是標準 code，標準 label 是「拉麵」——
        // CuisineCatalog::label() 回傳的是**目錄裡的**標準 label，不是 feature 自己
        // 存的那份，兩者不一致時要以目錄為準，這裡刻意留一個不同的 feature label
        // 來釘住「用目錄、不是用 feature->label」這件事。
        $ramen = Feature::factory()->create(['code' => 'ramen', 'label' => '拉麵類']);
        $byCuisine->features()->attach($ramen->id);

        $data = $this->getJson('/api/v1/restaurants?keyword=拉麵')->json('data');
        $byId = collect($data)->keyBy('id');

        $this->assertSame(
            ['type' => 'name', 'value' => '拉麵屋', 'term' => '拉麵'],
            $byId[$byName->id]['matched_reasons'][0],
        );
        $this->assertSame(
            ['type' => 'cuisine', 'value' => '拉麵', 'term' => '拉麵'],
            $byId[$byCuisine->id]['matched_reasons'][0],
        );
    }

    public function test_matched_reasons_cover_locality_description_and_diet(): void
    {
        $byLocality = Restaurant::factory()->create([
            'name' => '甲蔬食', 'city' => '拉麵市', 'description' => null,
        ]);
        $byDescription = Restaurant::factory()->create([
            'name' => '乙蔬食', 'description' => '本店以拉麵聞名',
        ]);
        $byDiet = Restaurant::factory()->create(['name' => '丙蔬食', 'description' => null]);
        $byDiet->dietTypes()->attach(
            DietType::factory()->create(['code' => 'ramen_diet', 'label' => '拉麵素']),
        );

        $data = $this->getJson('/api/v1/restaurants?keyword=拉麵&venue_scope=all')->json('data');
        $byId = collect($data)->keyBy('id');

        $this->assertSame(
            ['type' => 'locality', 'value' => '拉麵市', 'term' => '拉麵'],
            $byId[$byLocality->id]['matched_reasons'][0],
        );
        $this->assertSame(
            ['type' => 'description', 'value' => '本店以拉麵聞名', 'term' => '拉麵'],
            $byId[$byDescription->id]['matched_reasons'][0],
        );
        $this->assertSame(
            ['type' => 'diet', 'value' => '拉麵素', 'term' => '拉麵'],
            $byId[$byDiet->id]['matched_reasons'][0],
        );
    }

    public function test_name_match_outranks_address_match(): void
    {
        // 地址剛好有「公益」兩個字的店，不應該排在店名就叫「公益」的前面。
        $addressOnly = Restaurant::factory()->create([
            'name' => '甲蔬食',
            'address' => '台中市西區公益路 100 號',
        ]);
        $byName = Restaurant::factory()->create([
            'name' => '公益蔬食',
            'address' => '台中市北區文心路 1 號',
        ]);

        $this->assertSame([$byName->id, $addressOnly->id], $this->search('keyword=公益'));
    }

    public function test_exact_name_outranks_partial_name(): void
    {
        $partial = Restaurant::factory()->create(['name' => '十方齋素食館']);
        $exact = Restaurant::factory()->create(['name' => '十方齋']);

        $this->assertSame([$exact->id, $partial->id], $this->search('keyword=十方齋'));
    }

    public function test_multiple_terms_are_combined_with_and(): void
    {
        $both = Restaurant::factory()->create(['name' => '拉麵屋', 'city' => '台中市']);
        Restaurant::factory()->create(['name' => '拉麵屋', 'city' => '台北市']);
        Restaurant::factory()->create(['name' => '蔬食館', 'city' => '台中市']);

        $this->assertSame([$both->id], $this->search('keyword='.urlencode('台中 拉麵')));
    }

    public function test_like_wildcards_in_the_keyword_are_escaped(): void
    {
        // 不跳脫的話 "%" 會變成萬用字元，這個查詢會撈回全部餐廳。
        Restaurant::factory()->create(['name' => '甲蔬食']);
        Restaurant::factory()->create(['name' => '乙蔬食']);

        $this->assertSame([], $this->search('keyword='.urlencode('%')));
    }

    public function test_relevance_sort_requires_a_keyword(): void
    {
        $this->getJson('/api/v1/restaurants?sort=relevance')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_explicit_sort_still_wins_over_relevance_default(): void
    {
        $older = Restaurant::factory()->create(['name' => '蔬食一號']);
        $newer = Restaurant::factory()->create(['name' => '蔬食二號']);

        // 預設會是 relevance；明確要 newest 就照做。
        $this->assertSame([$newer->id, $older->id], $this->search('keyword=蔬食&sort=newest'));
    }

    public function test_keyword_search_still_respects_other_filters(): void
    {
        $match = Restaurant::factory()->create(['name' => '蔬食一號', 'price_level' => 1]);
        Restaurant::factory()->create(['name' => '蔬食二號', 'price_level' => 4]);

        $this->assertSame([$match->id], $this->search('keyword=蔬食&price_level=1'));
    }

    public function test_cursor_pagination_under_relevance_sort_never_repeats_or_skips(): void
    {
        // 全部命中同一個關鍵字、店名形狀相同＝相關性同分，這是最容易翻頁翻壞的情況。
        $expected = [];

        foreach (range(1, 11) as $i) {
            $expected[] = Restaurant::factory()->create(['name' => "蔬食小館 {$i}"])->id;
        }

        $seen = [];
        $cursor = null;

        do {
            $url = '/api/v1/restaurants?keyword=蔬食&per_page=4'.($cursor ? "&cursor={$cursor}" : '');
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
     * 搜「拉麵」跳出一家店名、地址、料理種類都沒有那兩個字的店時，使用者看不出
     * 關聯——不說明的話這筆結果看起來像 bug。
     */
    public function test_result_says_which_menu_item_matched(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => '綠光食堂']);
        MenuItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => '味噌拉麵',
            'diet_type' => 'vegan',
        ]);

        $data = $this->getJson('/api/v1/restaurants?keyword=拉麵')->json('data.0');

        $this->assertSame(['味噌拉麵'], $data['matched_menu_items']);
    }

    public function test_no_match_reason_key_when_the_name_itself_matched(): void
    {
        Restaurant::factory()->create(['name' => '拉麵屋']);

        $data = $this->getJson('/api/v1/restaurants?keyword=拉麵')->json('data.0');

        // 店名本身就命中時不需要解釋，多一行只是雜訊。
        $this->assertArrayNotHasKey('matched_menu_items', $data);
    }

    public function test_match_reasons_do_not_cause_a_query_per_row(): void
    {
        // 逐筆補查就是 N+1。整頁一次查完，所以菜色查詢固定只有一次。
        foreach (range(1, 5) as $i) {
            $restaurant = Restaurant::factory()->create(['name' => "食堂 {$i}"]);
            MenuItem::factory()->create([
                'restaurant_id' => $restaurant->id,
                'name' => '味噌拉麵',
                'diet_type' => 'vegan',
            ]);
        }

        $menuQueries = 0;
        \DB::listen(function ($query) use (&$menuQueries) {
            // 只數「另外整批去查菜色」那一支。主查詢裡也有兩處提到 menu_items
            // （相關性的 EXISTS、以及 whereHas 編譯出來的 exists 子句），但那些
            // 是同一次查詢的一部分——用批次載入特有的 `where restaurant_id in`
            // 形狀來認，才不會把它們算進來。
            if (str_contains($query->sql, 'from `menu_items` where `restaurant_id` in')) {
                $menuQueries++;
            }
        });

        $this->getJson('/api/v1/restaurants?keyword=拉麵')->assertOk()->assertJsonCount(5, 'data');

        $this->assertSame(1, $menuQueries, "菜色查詢應該只有一次，實際 {$menuQueries} 次");
    }
}
