<?php

namespace Tests\Unit;

use App\Repositories\RestaurantRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `boundingBoxPolygon()` 是純數學（沒有任何 DB 呼叫），但目前只透過
 * tests/Feature/Api/RestaurantTest.php 的完整 HTTP 半徑搜尋間接測到——那些測試
 * 驗證的是「搜尋結果排序對不對」，沒有人直接斷言過這個方法算出來的四個角座標本身
 * 精不精確。這裡直接測算式本身，不需要 DB／Laravel container，用純 PHPUnit
 * TestCase（不是 Tests\TestCase）避免不必要地啟動整個 framework。
 */
class RestaurantRepositoryBoundingBoxTest extends TestCase
{
    private function boundingBoxPolygon(float $lat, float $lng, float $radiusKm): string
    {
        $method = new ReflectionMethod(RestaurantRepository::class, 'boundingBoxPolygon');
        $method->setAccessible(true);

        return $method->invoke(new RestaurantRepository, $lat, $lng, $radiusKm);
    }

    public function test_returns_a_closed_wkt_polygon_with_five_points(): void
    {
        $wkt = $this->boundingBoxPolygon(24.1477, 120.6736, 5);

        $this->assertMatchesRegularExpression('/^POLYGON\(\(.+\)\)$/', $wkt);

        $points = $this->parsePoints($wkt);
        $this->assertCount(5, $points, 'WKT polygon 環必須首尾同一點才會封閉，共 5 個座標。');
        $this->assertSame($points[0], $points[4], '第一個點跟最後一個點必須相同，環才會封閉。');
    }

    public function test_corners_match_the_expected_lat_lng_delta_at_the_equator_like_latitude(): void
    {
        // 緯度接近 0 時 cos(lat) ≈ 1，lngDelta 跟 latDelta 應該幾乎相等，方便手算驗證。
        $lat = 0.0;
        $lng = 0.0;
        $radiusKm = 10.0;

        $wkt = $this->boundingBoxPolygon($lat, $lng, $radiusKm);
        $points = $this->parsePoints($wkt);

        $expectedDelta = $radiusKm / 111.32;

        $lngs = array_map(fn ($p) => $p[0], $points);
        $lats = array_map(fn ($p) => $p[1], $points);

        $this->assertEqualsWithDelta(-$expectedDelta, min($lngs), 0.0001);
        $this->assertEqualsWithDelta($expectedDelta, max($lngs), 0.0001);
        $this->assertEqualsWithDelta(-$expectedDelta, min($lats), 0.0001);
        $this->assertEqualsWithDelta($expectedDelta, max($lats), 0.0001);
    }

    public function test_longitude_delta_widens_at_higher_latitude_because_meridians_converge(): void
    {
        // 緯度越高，同樣的公里數對應到的經度差越大（cos(lat) 越小，lngDelta = radius/(111.32*cos(lat))
        // 越大）——這是地球是球體而不是平面地圖格線的直接後果，錯了的話高緯度的半徑搜尋
        // range 會抓太窄，漏掉東西兩側其實在半徑內的餐廳。
        $radiusKm = 10.0;

        $lowLatPoints = $this->parsePoints($this->boundingBoxPolygon(1.0, 120.0, $radiusKm));
        $highLatPoints = $this->parsePoints($this->boundingBoxPolygon(60.0, 120.0, $radiusKm));

        $lngSpread = fn (array $points) => max(array_map(fn ($p) => $p[0], $points)) - min(array_map(fn ($p) => $p[0], $points));

        $this->assertGreaterThan(
            $lngSpread($lowLatPoints),
            $lngSpread($highLatPoints),
            '緯度 60 度的經度跨幅應該明顯大於緯度 1 度，因為經線在高緯度收斂。'
        );
    }

    public function test_does_not_divide_by_zero_near_the_pole(): void
    {
        // cos(90°) = 0，程式碼用 max(cos(...), 0.000001) 防除以零；驗證真的不會噴例外
        // 或算出 NAN/INF，而不是只看程式碼「看起來」有防呆。
        $wkt = $this->boundingBoxPolygon(89.9999, 0.0, 5.0);
        $points = $this->parsePoints($wkt);

        foreach ($points as [$lngValue, $latValue]) {
            $this->assertIsFloat($lngValue);
            $this->assertIsFloat($latValue);
            $this->assertFalse(is_nan($lngValue) || is_infinite($lngValue));
            $this->assertFalse(is_nan($latValue) || is_infinite($latValue));
        }
    }

    /**
     * @return array<int, array{0: float, 1: float}> [lng, lat] 依 WKT 順序
     */
    private function parsePoints(string $wkt): array
    {
        preg_match('/^POLYGON\(\((.+)\)\)$/', $wkt, $matches);
        $this->assertNotEmpty($matches, "WKT 格式不對: {$wkt}");

        return array_map(function (string $pair) {
            [$lng, $lat] = array_map('floatval', explode(' ', trim($pair)));

            return [$lng, $lat];
        }, explode(',', $matches[1]));
    }
}
