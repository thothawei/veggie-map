<?php

namespace Tests\Feature\Console;

use App\Support\FilterUsageTelemetry;
use Tests\TestCase;

/** `php artisan search:filter-usage`——B4 量體評估的報表指令。 */
class SearchFilterUsageCommandTest extends TestCase
{
    public function test_says_there_is_no_data_when_nothing_was_recorded(): void
    {
        $this->artisan('search:filter-usage')
            ->expectsOutputToContain('還沒有資料')
            ->assertSuccessful();
    }

    public function test_lists_the_recorded_filters(): void
    {
        FilterUsageTelemetry::record(['open_now']);
        FilterUsageTelemetry::record(['open_now', 'price_level']);

        $this->artisan('search:filter-usage')
            ->expectsOutputToContain('共 2 次搜尋')
            ->expectsOutputToContain('open_now')
            ->assertSuccessful();
    }

    /** 樣本太少時要明講排名還不能用來改常駐項目——不然報表本身會變成憑猜的來源。 */
    public function test_warns_when_the_sample_is_too_small_to_act_on(): void
    {
        FilterUsageTelemetry::record(['open_now']);

        $this->artisan('search:filter-usage')
            ->expectsOutputToContain('樣本太少')
            ->assertSuccessful();
    }
}
