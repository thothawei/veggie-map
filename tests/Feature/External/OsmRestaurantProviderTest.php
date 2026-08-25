<?php

namespace Tests\Feature\External;

use App\Models\ExternalApiLog;
use App\Services\External\BoundingBox;
use App\Services\External\OsmRestaurantProvider;
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
            2,
            substr_count($this->sentQuery(), '(25,121.51,25.07,121.58)'),
        );
    }

    public function test_yes_mode_also_accepts_places_that_merely_offer_veg_options(): void
    {
        Http::fake(['*' => Http::response(['elements' => []])]);

        (new OsmRestaurantProvider(OsmRestaurantProvider::DIET_YES))->fetch($this->bbox());

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
        $this->assertEqualsCanonicalizing(['vegetarian', 'vegan'], $results[0]->dietCodes);
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
