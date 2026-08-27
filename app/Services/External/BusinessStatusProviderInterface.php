<?php

namespace App\Services\External;

use App\Models\Restaurant;

/**
 * 查一家店是不是永久歇業。
 *
 * 抽成介面的理由跟 RestaurantProviderInterface 一樣：真正權威的來源是
 * Google Places 的 `business_status`（需要付費帳號與 API key），而免費、
 * 現在就能跑的是 OSM 的 `disused:`／`was:` 標籤。兩者都可能失效或改規則，
 * 所以呼叫端只認這個介面。
 *
 * 一次查一批而不是一家一家問：外部 API 幾乎都對「請求次數」收費或設限，
 * 逐筆查一千多家會是一千多次往返。
 */
interface BusinessStatusProviderInterface
{
    /**
     * @param  iterable<Restaurant>  $restaurants
     * @return array<int, BusinessStatus> key 是 restaurant id；查不到的一律 Unknown
     */
    public function statusFor(iterable $restaurants): array;

    /** 用於 log 與指令輸出，讓人知道這批判斷是誰給的。 */
    public function name(): string;
}
