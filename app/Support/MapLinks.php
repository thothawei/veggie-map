<?php

namespace App\Support;

/**
 * 外部地圖連結。
 *
 * 存在的理由是「同一個網址格式不要有兩份」——這個字串原本直接寫在
 * ClosureSignalController 裡，跟前端 `resources/js/lib/geo.ts` 的 googleMapsUrl()
 * 各拼各的，改一邊另一邊不會跟著改。
 *
 * 前後端仍然各有一份實作（跨語言沒辦法共用程式碼），但各自只有一個來源，
 * 而且兩邊都有測試釘住格式。
 */
final class MapLinks
{
    /**
     * 用**座標**而不是店名：OSM 的店名跟 Google 上的未必一致（分店名、
     * 日文漢字寫法），拿名字去搜有機會定位到另一家同名的店。座標一定對。
     */
    public static function googleMaps(float $latitude, float $longitude): string
    {
        return sprintf(
            'https://www.google.com/maps/search/?api=1&query=%s,%s',
            $latitude,
            $longitude,
        );
    }
}
