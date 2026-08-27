<?php

namespace Tests\Unit;

use App\Support\MapLinks;
use PHPUnit\Framework\TestCase;

class MapLinksTest extends TestCase
{
    public function test_uses_coordinates_not_the_shop_name(): void
    {
        // 拿店名去搜有機會定位到另一家同名的店；座標一定指向這家店本人。
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=25.033,121.5654',
            MapLinks::googleMaps(25.033, 121.5654),
        );
    }

    public function test_negative_coordinates_survive(): void
    {
        $this->assertStringContainsString(
            'query=-33.8688,-70.6693',
            MapLinks::googleMaps(-33.8688, -70.6693),
        );
    }

    /**
     * 前端 resources/js/lib/geo.ts 的 googleMapsUrl() 產生同一個格式。
     * 這條測試的用途是：改格式時兩邊都會被迫更新（geo.test.ts 有對應的一條）。
     */
    public function test_matches_the_frontend_format(): void
    {
        $this->assertStringStartsWith(
            'https://www.google.com/maps/search/?api=1&query=',
            MapLinks::googleMaps(1.0, 2.0),
        );
    }
}
