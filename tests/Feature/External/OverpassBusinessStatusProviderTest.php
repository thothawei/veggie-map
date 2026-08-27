<?php

namespace Tests\Feature\External;

use App\Models\Restaurant;
use App\Services\External\BusinessStatus;
use App\Services\External\OverpassBusinessStatusProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverpassBusinessStatusProviderTest extends TestCase
{
    use RefreshDatabase;

    private function osmRestaurant(string $nodeId): Restaurant
    {
        return Restaurant::factory()->create(['source' => 'osm', 'source_id' => $nodeId]);
    }

    private function fakeElements(array $elements): void
    {
        Http::fake([
            '*' => Http::response(['elements' => $elements]),
        ]);
    }

    public function test_disused_prefix_means_permanently_closed(): void
    {
        $restaurant = $this->osmRestaurant('123');
        // OSM 的慣例：歇業就把主要標籤加前綴，這是製圖者明確表達的判斷。
        $this->fakeElements([['id' => 123, 'tags' => ['disused:amenity' => 'restaurant']]]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        $this->assertSame(BusinessStatus::ClosedPermanently, $statuses[$restaurant->id]);
    }

    public function test_was_prefix_also_means_permanently_closed(): void
    {
        $restaurant = $this->osmRestaurant('124');
        $this->fakeElements([['id' => 124, 'tags' => ['was:shop' => 'deli', 'name' => '舊店']]]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        $this->assertSame(BusinessStatus::ClosedPermanently, $statuses[$restaurant->id]);
    }

    public function test_a_reoccupied_node_is_operational_not_closed(): void
    {
        // 真實案例（2026-08-27 查 overpass-api.de）：台北 node 299002404 的
        // disused:name 是 N.Y.Bagels Cafe，但 name 是「初泰」、amenity=restaurant
        // ——舊店收了、新店進駐同一個點位，生意還在做。
        // 只看 disused: 前綴就下架的話，被拿掉的正好是這種還在營業的店。
        $restaurant = $this->osmRestaurant('299002404');
        $this->fakeElements([[
            'id' => 299002404,
            'tags' => [
                'disused:amenity' => 'restaurant',
                'disused:name' => 'N.Y.Bagels Cafe',
                'amenity' => 'restaurant',
                'name' => '初泰',
            ],
        ]]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        $this->assertSame(BusinessStatus::Operational, $statuses[$restaurant->id]);
    }

    public function test_a_normal_node_is_operational(): void
    {
        $restaurant = $this->osmRestaurant('125');
        $this->fakeElements([['id' => 125, 'tags' => ['amenity' => 'restaurant', 'diet:vegan' => 'only']]]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        $this->assertSame(BusinessStatus::Operational, $statuses[$restaurant->id]);
    }

    public function test_a_node_that_disappeared_is_missing_not_closed(): void
    {
        // node 會因為被合併進 way／building、被改成別的 element、或單純被誤刪
        // 而消失——那些都不代表店收了。所以是 Missing（線索，交給人判斷）
        // 而不是 ClosedPermanently（自動下架）。
        $restaurant = $this->osmRestaurant('126');
        $this->fakeElements([]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        $this->assertSame(BusinessStatus::Missing, $statuses[$restaurant->id]);
    }

    public function test_a_failed_request_yields_unknown_not_missing(): void
    {
        // 這條守的是「Overpass 掛掉不能變成一批假的疑似歇業」。
        // 請求失敗時整批沒有狀態，呼叫端就什麼都不做；如果這裡回 Missing，
        // 外部服務抖一下就會讓 Admin 的待審清單被上千家店洗版。
        $restaurant = $this->osmRestaurant('128');
        Http::fake(['*' => Http::response('boom', 503)]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        $this->assertArrayNotHasKey($restaurant->id, $statuses);
    }

    public function test_http_failure_yields_no_status_instead_of_guessing(): void
    {
        $restaurant = $this->osmRestaurant('127');
        Http::fake(['*' => Http::response('boom', 500)]);

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$restaurant]);

        // 外部掛掉時不要猜。回空的，呼叫端就會維持 Unknown、不動任何一家店。
        $this->assertArrayNotHasKey($restaurant->id, $statuses);
        $this->assertDatabaseHas('external_api_logs', [
            'provider' => 'overpass',
            'success' => false,
            'error_code' => 'HTTP_500',
        ]);
    }

    public function test_manually_created_restaurants_are_skipped(): void
    {
        // 手動建立的店沒有 OSM node id，這個 provider 無從判斷——不要瞎猜，
        // 也不要為了它送一次沒有意義的請求。
        $manual = Restaurant::factory()->create(['source' => 'manual', 'source_id' => null]);
        Http::fake();

        $statuses = (new OverpassBusinessStatusProvider)->statusFor([$manual]);

        $this->assertSame([], $statuses);
        Http::assertNothingSent();
    }
}
