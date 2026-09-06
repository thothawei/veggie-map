<?php

namespace Tests\Feature\Console;

use App\Models\SearchMiss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** `php artisan search:misses`——A8 的報表指令。 */
class SearchMissesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>|null  $activeFilters */
    private function miss(
        string $keyword,
        ?string $normalized = null,
        bool $hadFilters = false,
        ?string $createdAt = null,
        ?array $activeFilters = null,
    ): SearchMiss {
        return SearchMiss::create([
            'keyword' => $keyword,
            'normalized' => $normalized ?? mb_strtolower($keyword),
            'result_count' => 0,
            'had_filters' => $hadFilters,
            'active_filters' => $activeFilters,
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

    /**
     * B4 量體評估：哪個篩選鍵最常跟零結果一起出現，是決定 B3 常駐 quick
     * filter 該是哪三個的依據——這條守的是排行榜數字算得對。
     */
    public function test_filter_breakdown_ranks_by_how_often_each_key_appears(): void
    {
        $this->miss('拉麵', hadFilters: true, activeFilters: ['open_now']);
        $this->miss('滷味', hadFilters: true, activeFilters: ['open_now', 'confidence_min']);
        $this->miss('火鍋', hadFilters: true, activeFilters: ['diet']);

        $this->artisan('search:misses')
            ->expectsOutputToContain('篩選分佈')
            ->expectsOutputToContain('open_now')
            ->expectsOutputToContain('confidence_min')
            ->expectsOutputToContain('diet')
            ->assertSuccessful();
    }

    public function test_filter_breakdown_says_no_data_yet_when_nothing_had_filters(): void
    {
        $this->miss('拉麵');

        $this->artisan('search:misses')
            ->expectsOutputToContain('還沒有資料可以回答哪三個該常駐')
            ->assertSuccessful();
    }

    /** 反向驗證用的判準：跳出保留期限的資料不該混進排行榜。 */
    public function test_filter_breakdown_only_looks_back_the_requested_window(): void
    {
        // 太久以前的那筆帶了 open_now，如果視窗判斷失效，它會混進排行榜；
        // 另外放一筆在視窗內、沒有篩選的 miss，讓指令走到列印排行榜那一段
        // （不然全部 miss 都太舊，指令會在更前面的「沒有任何零結果查詢」
        // 分支就先結束，根本測不到這裡要測的東西）。
        $this->miss('太久以前', hadFilters: true, activeFilters: ['open_now'], createdAt: now()->subDays(30));
        $this->miss('最近', createdAt: now()->subHours(1));

        $this->artisan('search:misses', ['--since' => '7d'])
            ->expectsOutputToContain('還沒有資料可以回答哪三個該常駐')
            ->doesntExpectOutputToContain('open_now')
            ->assertSuccessful();
    }
}
