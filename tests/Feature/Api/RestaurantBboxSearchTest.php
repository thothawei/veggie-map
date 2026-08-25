<?php

namespace Tests\Feature\Api;

use App\Models\Feature;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bbox` 是給城市切換用的：城市範圍本來就是矩形，而且台中／高雄的半對角線分別是
 * 59.6km／66.4km，換算成 `radius` 會直接撞上 `max:50` 的驗證上限。
 */
class RestaurantBboxSearchTest extends TestCase
{
    use RefreshDatabase;

    private const TAICHUNG_BBOX = '23.9500,120.4300,24.4500,121.4700';

    private function restaurantAt(float $lat, float $lng, string $name): Restaurant
    {
        return Restaurant::factory()->create([
            'name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'location' => DB::raw("ST_SRID(POINT({$lng}, {$lat}), 4326)"),
        ]);
    }

    public function test_bbox_returns_only_restaurants_inside_the_rectangle(): void
    {
        $this->restaurantAt(24.1477, 120.6736, '台中市中心');
        $this->restaurantAt(25.0330, 121.5654, '台北市中心');
        $this->restaurantAt(22.6273, 120.3014, '高雄市中心');

        $response = $this->getJson('/api/v1/restaurants?bbox='.self::TAICHUNG_BBOX);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '台中市中心');
    }

    public function test_bbox_covers_a_span_that_radius_search_cannot_express(): void
    {
        // 台中 bbox 東緣（和平區山區）離市中心約 80km，遠超過 radius 上限 50km。
        // 用半徑做不到的事，bbox 要做得到，否則城市切換就會漏掉城市邊緣的店。
        $this->restaurantAt(24.2000, 121.4000, '和平區');

        $this->getJson('/api/v1/restaurants?bbox='.self::TAICHUNG_BBOX)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/restaurants?latitude=24.2&longitude=120.95&radius=60')
            ->assertStatus(422)
            ->assertJsonPath('error.fields.radius.0', 'The radius field must not be greater than 50.');
    }

    /**
     * MySQL 的 `MBRContains` 對邊界是**嚴格排除**的——直接下 SQL 驗過：座標剛好落在角落
     * 回 0，往內縮 1e-9 回 1。這不是這次 bbox 才有的行為，既有的半徑搜尋用的是同一個
     * 函式。實務上 OSM 座標有 7 位小數、我們的 bbox 只有 4 位，剛好壓在邊界的機率趨近於零，
     * 所以維持現狀不做 epsilon 補償（那會連帶改到半徑搜尋的語意）。這裡把行為釘住，
     * 免得日後有人假設它是包含邊界的。
     */
    public function test_bbox_boundary_is_exclusive(): void
    {
        $this->restaurantAt(23.9500, 120.4300, '正好在西南角');
        $this->restaurantAt(23.9501, 120.4301, '角落內側');

        $this->getJson('/api/v1/restaurants?bbox='.self::TAICHUNG_BBOX)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '角落內側');
    }

    public function test_bbox_combines_with_other_filters(): void
    {
        $this->restaurantAt(24.1477, 120.6736, '台中有停車');
        $this->restaurantAt(24.1500, 120.6800, '台中沒停車');
        $this->restaurantAt(25.0330, 121.5654, '台北有停車');

        // 測試資料庫沒有 seed lookup 表（TestCase 沒有跑 seeder），features 是空的——
        // 直接 where('code','parking')->value('id') 會拿到 null，attach 等於什麼都沒做。
        $parking = Feature::factory()->create(['code' => 'parking']);

        Restaurant::where('name', 'like', '%有停車%')->get()
            ->each(fn (Restaurant $r) => $r->features()->attach($parking));

        $this->getJson('/api/v1/restaurants?bbox='.self::TAICHUNG_BBOX.'&parking=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '台中有停車');
    }

    /**
     * @dataProvider invalidBboxes
     */
    public function test_malformed_bbox_is_rejected_instead_of_silently_searching_the_whole_world(string $bbox): void
    {
        // 靜默忽略壞掉的 bbox 會讓「查這座城市」變成「查全世界」，使用者只會看到
        // 莫名其妙的結果而不是錯誤訊息。
        $this->getJson('/api/v1/restaurants?bbox='.urlencode($bbox))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['bbox']]]);
    }

    public static function invalidBboxes(): array
    {
        return [
            '不是座標' => ['not-a-bbox'],
            '只有三個數字' => ['1,2,3'],
            '五個數字' => ['1,2,3,4,5'],
            '角落顛倒' => ['24.45,121.47,23.95,120.43'],
            '緯度超出範圍' => ['99,120,100,121'],
            '經度超出範圍' => ['24,181,25,182'],
            '空字串以外的空白' => ['  ,  ,  ,  '],
        ];
    }

    public function test_bbox_with_coordinates_sorts_by_distance_without_clipping_the_corners(): void
    {
        // 帶座標是為了距離排序；此時邊界仍應由矩形決定，不能再套半徑把四角切掉。
        $this->restaurantAt(24.4400, 121.4600, '東北角遠處');
        $this->restaurantAt(24.1500, 120.6800, '中心附近');

        $response = $this->getJson(
            '/api/v1/restaurants?bbox='.self::TAICHUNG_BBOX.'&latitude=24.1477&longitude=120.6736&sort=distance'
        );

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '中心附近');
    }
}
