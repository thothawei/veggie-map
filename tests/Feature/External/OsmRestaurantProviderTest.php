<?php

namespace Tests\Feature\External;

use App\Models\ExternalApiLog;
use App\Services\External\BoundingBox;
use App\Services\External\OsmRestaurantProvider;
use App\Support\DietCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OsmRestaurantProviderTest extends TestCase
{
    use RefreshDatabase;

    private function bbox(): BoundingBox
    {
        return new BoundingBox(25.00, 121.51, 25.07, 121.58);
    }

    private function sentQuery(): string
    {
        $sent = '';

        Http::assertSent(function (Request $request) use (&$sent) {
            $sent = $request->data()['data'] ?? '';

            return true;
        });

        return $sent;
    }

    public function test_query_only_asks_overpass_for_exclusively_vegetarian_or_vegan_places(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => []]),
        ]);

        (new OsmRestaurantProvider)->fetch($this->bbox());

        $query = $this->sentQuery();

        // 只收整間店都是素／純素的（diet:*=only）。`yes` 只代表「有素食選項」的一般餐廳，
        // 2026-08-25 決定不收——沒有這個篩選，台北市 bbox 會撈回 15,974 家而不是 222 家。
        $this->assertStringContainsString('["diet:vegetarian"="only"]', $query);
        $this->assertStringContainsString('["diet:vegan"="only"]', $query);
        $this->assertStringNotContainsString('="yes"', $query);
    }

    public function test_every_node_statement_carries_a_diet_filter(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => []]),
        ]);

        (new OsmRestaurantProvider)->fetch($this->bbox());

        preg_match_all('/^\s*node\[.*$/m', $this->sentQuery(), $matches);

        $this->assertNotEmpty($matches[0], '查詢裡至少要有一條 node statement');

        foreach ($matches[0] as $statement) {
            $this->assertStringContainsString(
                '"only"',
                $statement,
                "有一條 node statement 沒有 diet 篩選，會把整個 bbox 的餐廳都撈回來：{$statement}",
            );
        }
    }

    public function test_bbox_is_passed_through_to_every_statement(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => []]),
        ]);

        (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertSame(
            count(DietCatalog::osmTags()),
            substr_count($this->sentQuery(), '(25,121.51,25.07,121.58)'),
        );
    }

    public function test_yes_mode_also_accepts_places_that_merely_offer_veg_options(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        (new OsmRestaurantProvider('yes'))->fetch($this->bbox());

        $query = $this->sentQuery();

        // 日本用這個規則：東京 23 区只有 46/210 家標 only，套 only 會讓地圖薄到不可用。
        // 必須是 ^(yes|only)$ 而不是 ="yes"——純素食店本來就該落在這個較寬的集合裡。
        $this->assertStringContainsString('["diet:vegetarian"~"^(yes|only)$"]', $query);
        $this->assertStringContainsString('["diet:vegan"~"^(yes|only)$"]', $query);
        $this->assertStringNotContainsString('="only"', $query);
    }

    public function test_unknown_diet_mode_is_rejected_instead_of_silently_falling_back(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OsmRestaurantProvider('vegan');
    }

    public function test_sends_a_real_user_agent_because_overpass_rejects_the_default_one(): void
    {
        config(['services.overpass.user_agent' => 'VeggieMap/1.0 (+https://example.test/repo)']);

        Http::fake(['*' => Http::response(['elements' => []])]);

        (new OsmRestaurantProvider)->fetch($this->bbox());

        // 少了這個 header，overpass-api.de 會回 HTTP 406，retry() 包成 RequestException，
        // 最後靜默回空陣列——同步報「成功 0 筆」而不是報錯。2026-08-25 實際踩過。
        Http::assertSent(fn (Request $request) => $request->hasHeader(
            'User-Agent',
            'VeggieMap/1.0 (+https://example.test/repo)',
        ));
    }

    public function test_parses_named_nodes_and_maps_diet_tags(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [
                [
                    'id' => 111,
                    'lat' => 25.03,
                    'lon' => 121.55,
                    'tags' => [
                        'name' => '秀羽素食',
                        'diet:vegetarian' => 'only',
                        'diet:vegan' => 'yes',
                        'addr:street' => '信義路',
                        'addr:housenumber' => '7',
                    ],
                ],
                // 沒有 name 的節點要被跳過，不能變成一筆無名餐廳
                ['id' => 222, 'lat' => 25.04, 'lon' => 121.56, 'tags' => ['diet:vegan' => 'only']],
            ]]),
        ]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertCount(1, $results);
        $this->assertSame('秀羽素食', $results[0]->name);
        $this->assertSame('111', $results[0]->sourceId);
        $this->assertSame('信義路 7', $results[0]->address);
        $this->assertEqualsCanonicalizing(['vegetarian', 'vegan_friendly'], $results[0]->dietCodes);
        $this->assertSame([], $results[0]->cuisineCodes);
    }

    public function test_japanese_address_tags_are_composed(): void
    {
        /*
         * 資料形狀取自真實節點（2026-08-27 查 overpass-api.de，東京千代田区）。
         *
         * 日本地址沒有街道名，用「都道府県 → 市区町村 → 町名 → 街区符号 → 号」。
         * 取樣 51 個東京素食餐廳節點：addr:neighbourhood 10 筆、addr:block_number
         * 10 筆、addr:province 9 筆，addr:full 只有 1 筆——原本這些欄位一個都沒讀，
         * 所以東京 195 家只有 41 家有地址。
         */
        Http::fake([
            '*' => Http::response(['elements' => [[
                'type' => 'node', 'id' => 1, 'lat' => 35.674, 'lon' => 139.755,
                'tags' => [
                    'name' => 'ベジハウス',
                    'diet:vegetarian' => 'only',
                    'addr:province' => '東京都',
                    'addr:city' => '千代田区',
                    'addr:neighbourhood' => '日比谷公園',
                    'addr:block_number' => '1',
                    'addr:housenumber' => '2',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider('only'))->fetch(new BoundingBox(35.5, 139.5, 35.9, 140.0));

        // 街区符号與号之間是短橫線，那是日本地址的寫法，不是我們自己編的分隔符。
        $this->assertSame('東京都千代田区日比谷公園1-2', $results[0]->address);
    }

    public function test_japanese_quarter_tag_is_placed_before_neighbourhood(): void
    {
        /*
         * 同樣取自真實節點（東京港区）。OSM 的日本地址標籤**用法不一致**：
         * 多數把町名與丁目合寫在 addr:neighbourhood（「銀座四丁目」），
         * 少數則是 addr:quarter=町名、addr:neighbourhood=丁目。
         * 兩種都要組得出正確順序，所以 quarter 排在 neighbourhood 前面。
         */
        Http::fake([
            '*' => Http::response(['elements' => [[
                'type' => 'node', 'id' => 2, 'lat' => 35.655, 'lon' => 139.736,
                'tags' => [
                    'name' => 'ヴィーガン食堂',
                    'diet:vegetarian' => 'only',
                    'addr:province' => '東京都',
                    'addr:city' => '港区',
                    'addr:quarter' => '麻布十番',
                    'addr:neighbourhood' => '4丁目',
                    'addr:block_number' => '3',
                    'addr:housenumber' => '1',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider('only'))->fetch(new BoundingBox(35.5, 139.5, 35.9, 140.0));

        $this->assertSame('東京都港区麻布十番4丁目3-1', $results[0]->address);
    }

    public function test_taiwanese_street_address_still_works(): void
    {
        // 加了日本欄位之後，原本的台灣地址不能壞。
        Http::fake([
            '*' => Http::response(['elements' => [[
                'type' => 'node', 'id' => 2, 'lat' => 24.14, 'lon' => 120.68,
                'tags' => [
                    'name' => '綠光食堂',
                    'diet:vegetarian' => 'only',
                    'addr:city' => '台中市',
                    'addr:district' => '西區',
                    'addr:street' => '公益路',
                    'addr:housenumber' => '100',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider('only'))->fetch(new BoundingBox(24.0, 120.5, 24.3, 120.8));

        $this->assertSame('台中市西區公益路 100', $results[0]->address);
    }

    public function test_city_and_street_are_composed_when_full_address_is_missing(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [[
                'id' => 555,
                'lat' => 24.14,
                'lon' => 120.67,
                'tags' => [
                    'name' => '有城市也有路名',
                    'diet:vegetarian' => 'only',
                    'addr:city' => '台中市',
                    'addr:district' => '西區',
                    'addr:street' => '公益路',
                    'addr:housenumber' => '100',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertSame('台中市西區公益路 100', $results[0]->address);
    }

    /**
     * 標籤存在但值是空字串的節點確實有。在來源這一層就正規化成 null，否則空字串
     * 一路寫進 DB——那是一個值，不是「沒有這個資訊」。
     */
    public function test_blank_locality_tags_become_null_not_empty_string(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [[
                'id' => 556,
                'lat' => 24.14,
                'lon' => 120.67,
                'tags' => [
                    'name' => '標籤是空的',
                    'diet:vegetarian' => 'only',
                    'addr:city' => '',
                    'addr:district' => '   ',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertNull($results[0]->city);
        $this->assertNull($results[0]->district);
    }

    public function test_cuisine_tag_maps_from_config_and_drops_diet_values(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [[
                'id' => 666,
                'lat' => 25.03,
                'lon' => 121.55,
                'tags' => [
                    'name' => '日泰素食',
                    'diet:vegetarian' => 'only',
                    'cuisine' => 'japanese;thai;vegetarian',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertEqualsCanonicalizing(['japanese', 'thai'], $results[0]->cuisineCodes);
    }

    public function test_yes_diet_tags_map_to_friendly_codes(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [[
                'id' => 444,
                'lat' => 35.66,
                'lon' => 139.70,
                'tags' => [
                    'name' => 'CoCo',
                    'diet:vegetarian' => 'yes',
                    'diet:vegan' => 'yes',
                ],
            ]]]),
        ]);

        $results = (new OsmRestaurantProvider('yes'))->fetch($this->bbox());

        $this->assertEqualsCanonicalizing(
            ['vegetarian_friendly', 'vegan_friendly'],
            $results[0]->dietCodes,
        );
    }

    public function test_address_falls_back_to_addr_full_when_street_is_missing(): void
    {
        Http::fake([
            '*' => Http::response(['elements' => [
                [
                    'id' => 333,
                    'lat' => 24.14,
                    'lon' => 120.67,
                    'tags' => [
                        'name' => '只有完整地址的店',
                        'diet:vegetarian' => 'only',
                        'addr:full' => '台中市西區公益路 100 號',
                    ],
                ],
            ]]),
        ]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertCount(1, $results);
        $this->assertSame('台中市西區公益路 100 號', $results[0]->address);
    }

    public function test_failed_response_is_logged_and_returns_empty(): void
    {
        Http::fake([
            '*' => Http::response('boom', 500),
        ]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        // Http::retry() 預設 throw：非 429 的失敗會丟 RequestException，走 catch 而不是
        // `if (! $success)`。log 必須留下真實狀態碼——原本這裡記的是沒有資訊量的
        // "RequestException"＋status=0，害 406 那次排查看不出是被擋還是連不上。
        $this->assertSame([], $results);
        $this->assertSame(1, ExternalApiLog::count());

        $log = ExternalApiLog::sole();
        $this->assertSame('overpass', $log->provider);
        $this->assertFalse((bool) $log->success);
        $this->assertSame('HTTP_500', $log->error_code);
        $this->assertSame(500, $log->status);
    }

    /**
     * @dataProvider featureTagCases
     *
     * @param  string[]  $expected
     */
    public function test_maps_osm_tags_to_feature_codes(array $tags, array $expected): void
    {
        Http::fake(['*' => Http::response(['elements' => [[
            'id' => 1,
            'lat' => 25.03,
            'lon' => 121.55,
            'tags' => array_merge(['name' => '測試店'], $tags),
        ]]])]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertEqualsCanonicalizing($expected, $results[0]->featureCodes);
    }

    public static function featureTagCases(): array
    {
        return [
            '外帶' => [['takeaway' => 'yes'], ['takeout']],
            '只做外帶' => [['takeaway' => 'only'], ['takeout']],
            '外送' => [['delivery' => 'yes'], ['delivery']],
            '只做外送' => [['delivery' => 'only'], ['delivery']],
            '戶外座位' => [['outdoor_seating' => 'yes'], ['outdoor_seating']],
            '露臺也算戶外座位' => [['outdoor_seating' => 'patio'], ['outdoor_seating']],
            'wifi' => [['internet_access' => 'wlan'], ['wifi']],
            '可訂位' => [['reservation' => 'yes'], ['reservation']],
            '需訂位也算可訂位' => [['reservation' => 'required'], ['reservation']],
            '可帶寵物' => [['dog' => 'yes'], ['pet_friendly']],
            '牽繩也算可帶寵物' => [['dog' => 'leashed'], ['pet_friendly']],
            '多個特色' => [
                ['takeaway' => 'yes', 'delivery' => 'yes', 'internet_access' => 'wlan'],
                ['takeout', 'delivery', 'wifi'],
            ],
            '沒有任何特色標籤' => [[], []],
        ];
    }

    /**
     * @dataProvider negativeTagCases
     */
    public function test_negative_tag_values_do_not_become_features(string $tag, string $value): void
    {
        // 這是整個對應最容易寫錯的地方：只看 key 存在就掛上特色的話，實測台中＋東京共
        // 387 筆節點裡 `outdoor_seating=no` 有 32 筆（比 yes 的 10 筆還多）、
        // `delivery=no` 5 筆，全都會被標成「有」。把明確說沒有的店標成有，
        // 比漏收嚴重得多——使用者會白跑一趟。
        Http::fake(['*' => Http::response(['elements' => [[
            'id' => 1,
            'lat' => 25.03,
            'lon' => 121.55,
            'tags' => ['name' => '測試店', $tag => $value],
        ]]])]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertSame([], $results[0]->featureCodes, "{$tag}={$value} 不該被當成有這個特色");
    }

    public static function negativeTagCases(): array
    {
        return [
            '沒有外帶' => ['takeaway', 'no'],
            '沒有外送' => ['delivery', 'no'],
            '沒有戶外座位' => ['outdoor_seating', 'no'],
            '沒有網路' => ['internet_access', 'no'],
            '不能訂位' => ['reservation', 'no'],
            '不能帶寵物' => ['dog', 'no'],
            '無法辨識的值' => ['takeaway', 'maybe'],
            '空字串' => ['delivery', ''],
        ];
    }

    public function test_features_without_a_usable_osm_tag_are_left_alone(): void
    {
        // parking 與 family_friendly 在台中＋東京共 387 筆節點裡是 0 筆（含
        // capacity:parking／kids_area 等變體）。沒有可用標籤就不硬湊對應——
        // 寧可讓那兩個篩選維持空的，也不要標錯。
        Http::fake(['*' => Http::response(['elements' => [[
            'id' => 1,
            'lat' => 25.03,
            'lon' => 121.55,
            'tags' => ['name' => '測試店', 'takeaway' => 'yes', 'capacity' => '30', 'smoking' => 'no'],
        ]]])]);

        $results = (new OsmRestaurantProvider)->fetch($this->bbox());

        $this->assertSame(['takeout'], $results[0]->featureCodes);
    }
}
