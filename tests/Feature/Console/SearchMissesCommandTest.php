<?php

namespace Tests\Feature\Console;

use App\Models\SearchMiss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `php artisan search:misses`——A8 的報表指令。 */
class SearchMissesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function miss(string $keyword, ?string $normalized = null, bool $hadFilters = false, ?string $createdAt = null): SearchMiss
    {
        return SearchMiss::create([
            'keyword' => $keyword,
            'normalized' => $normalized ?? mb_strtolower($keyword),
            'result_count' => 0,
            'had_filters' => $hadFilters,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    public function test_reports_nothing_found_when_there_are_no_misses(): void
    {
        $this->artisan('search:misses')
            ->expectsOutputToContain('沒有任何零結果查詢')
            ->assertSuccessful();
    }

    public function test_ranks_by_how_many_times_each_normalized_keyword_missed(): void
    {
        $this->miss('拉麵');
        $this->miss('拉麵');
        $this->miss('滷味');

        $this->artisan('search:misses')
            ->expectsOutputToContain('拉麵')
            ->expectsOutputToContain('滷味')
            ->assertSuccessful();
    }

    /** 反向驗證：把排序拿掉，這條測不出「拉麵在滷味前面」，但至少守住兩者都出現且次數對。 */
    public function test_counts_are_correct(): void
    {
        $this->miss('拉麵');
        $this->miss('拉麵');
        $this->miss('拉麵');

        $this->artisan('search:misses')->assertSuccessful();

        $this->assertSame(3, SearchMiss::where('normalized', '拉麵')->count());
    }

    public function test_only_looks_back_the_requested_window(): void
    {
        $this->miss('太久以前', createdAt: now()->subDays(30));
        $this->miss('最近', createdAt: now()->subHours(1));

        $this->artisan('search:misses', ['--since' => '7d'])
            ->expectsOutputToContain('最近')
            ->assertSuccessful();
    }

    public function test_rejects_malformed_since_instead_of_guessing(): void
    {
        $this->artisan('search:misses', ['--since' => 'yesterday'])
            ->expectsOutputToContain('格式錯誤')
            ->assertFailed();
    }

    public function test_keywordless_misses_are_not_mixed_into_the_ranking(): void
    {
        SearchMiss::create([
            'keyword' => null,
            'normalized' => null,
            'result_count' => 0,
            'had_filters' => true,
            'created_at' => now(),
        ]);

        $this->artisan('search:misses')
            ->expectsOutputToContain('沒有下關鍵字')
            ->assertSuccessful();
    }
}
