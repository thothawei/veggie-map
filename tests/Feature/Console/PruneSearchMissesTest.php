<?php

namespace Tests\Feature\Console;

use App\Models\SearchMiss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `php artisan search-misses:prune`——A8 保留 90 天，排程清舊資料。 */
class PruneSearchMissesTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_records_older_than_the_retention_window(): void
    {
        SearchMiss::create([
            'keyword' => '太久以前', 'normalized' => '太久以前',
            'result_count' => 0, 'had_filters' => false,
            'created_at' => now()->subDays(91),
        ]);
        SearchMiss::create([
            'keyword' => '還在保留期內', 'normalized' => '還在保留期內',
            'result_count' => 0, 'had_filters' => false,
            'created_at' => now()->subDays(89),
        ]);

        $this->artisan('search-misses:prune')->assertSuccessful();

        $this->assertDatabaseCount('search_misses', 1);
        $this->assertDatabaseHas('search_misses', ['keyword' => '還在保留期內']);
    }

    public function test_days_option_overrides_the_default_90(): void
    {
        SearchMiss::create([
            'keyword' => '五天前', 'normalized' => '五天前',
            'result_count' => 0, 'had_filters' => false,
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('search-misses:prune', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseCount('search_misses', 0);
    }

    /** 反向驗證：拿掉 WHERE 條件會把還在保留期內的紀錄也刪掉，這條會紅。 */
    public function test_does_not_touch_records_within_the_window(): void
    {
        SearchMiss::create([
            'keyword' => '今天', 'normalized' => '今天',
            'result_count' => 0, 'had_filters' => false,
            'created_at' => now(),
        ]);

        $this->artisan('search-misses:prune')->assertSuccessful();

        $this->assertDatabaseCount('search_misses', 1);
    }
}
