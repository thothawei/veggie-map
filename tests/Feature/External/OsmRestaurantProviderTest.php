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
}
