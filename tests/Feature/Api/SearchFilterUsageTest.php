<?php

namespace Tests\Feature\Api;

use App\Support\DietCatalog;
use App\Support\FilterUsageTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「哪個篩選最常被按」的計數器（B4 量體評估）。
 *
 * 這組測試守的是：**每次搜尋都記**（不像 search_misses 只在零結果時寫）、
 * 分母含沒開篩選的搜尋、隱性預設值不算使用者開了篩選。
 */
class SearchFilterUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_every_search_not_only_the_zero_result_ones(): void
    {
        $this->getJson('/api/v1/restaurants?open_now=1')->assertOk();
        $this->getJson('/api/v1/restaurants?open_now=1')->assertOk();

        $report = FilterUsageTelemetry::report(1);

        $this->assertSame(2, $report['total']);
        $this->assertSame(2, $report['counts']['open_now']);
    }

    /** 沒開任何篩選的搜尋也要進分母，否則「使用比例」會被系統性高估。 */
    public function test_searches_without_filters_still_count_towards_the_total(): void
    {
        $this->getJson('/api/v1/restaurants')->assertOk();
        $this->getJson('/api/v1/restaurants?price_level=2')->assertOk();

        $report = FilterUsageTelemetry::report(1);

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['counts']['price_level']);
        $this->assertSame(0, $report['counts']['open_now']);
    }

    /** venue_scope 的預設值是每個請求都會帶的隱性預設，不是使用者主動收窄。 */
    public function test_default_venue_scope_is_not_counted_as_an_active_filter(): void
    {
        $param = DietCatalog::venueScopeParam();

        $this->getJson('/api/v1/restaurants?'.$param.'='.DietCatalog::venueScopeDefault())->assertOk();

        $report = FilterUsageTelemetry::report(1);

        $this->assertSame(1, $report['total']);
        $this->assertSame(0, $report['counts'][$param]);
    }

    /** 反向驗證：把記錄那行拿掉，上面幾條會紅——這條守的是「有記到別的端點以外的地方」不會發生。 */
    public function test_report_is_empty_before_any_search(): void
    {
        $report = FilterUsageTelemetry::report(30);

        $this->assertSame(0, $report['total']);
        $this->assertSame([], array_filter($report['counts']));
    }
}
