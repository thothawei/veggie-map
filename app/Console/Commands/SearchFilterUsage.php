<?php

namespace App\Console\Commands;

use App\Support\FilterUsageTelemetry;
use Illuminate\Console\Command;

/**
 * 「哪三個篩選該常駐 quick filter」的量體報表（B4）。
 *
 * B3 現在的三個（店家類型／營業中／高度可信）是規劃暫定的，不是量出來的。
 * `search:misses` 的「篩選分佈」只涵蓋零結果查詢，回答的是「哪個篩選最容易把
 * 結果篩成 0 家」——那恰好是最**不**該常駐的候選。這支指令看的是**所有**搜尋
 * 的分佈，那才是「哪個最常被按」。
 *
 * 兩張表一起看才有結論：常駐的條件是「常被用」（這裡）且「不常把結果殺光」
 * （`search:misses` 的篩選分佈）。
 */
class SearchFilterUsage extends Command
{
    protected $signature = 'search:filter-usage
        {--days=30 : 回看幾天（計數器按日切，最多保留 100 天）}';

    protected $description = '列出每個搜尋篩選被使用的次數，作為 B3 常駐 quick filter 該是哪三個的依據';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        ['total' => $total, 'counts' => $counts] = FilterUsageTelemetry::report($days);

        if ($total === 0) {
            $this->warn("最近 {$days} 天沒有任何搜尋被記錄，還沒有資料可以回答哪三個該常駐。");
            $this->line('計數器存在 cache（重啟 Redis 會歸零），而且只有 /api/restaurants 的搜尋會記。');

            return self::SUCCESS;
        }

        $this->info("最近 {$days} 天共 {$total} 次搜尋。每一列是「有開這個篩選的搜尋佔多少」：");

        $this->table(
            ['篩選鍵', '使用次數', '佔全部搜尋'],
            collect($counts)->map(fn (int $count, string $key) => [
                $key,
                $count,
                sprintf('%.1f%%', $count / $total * 100),
            ])->values()->all(),
        );

        // 數字很少的時候排名是雜訊。門檻取 100 次搜尋：低於這個量，前三名換人
        // 可能只是幾次點擊的差別，照著它改常駐項目跟憑猜沒有兩樣。
        if ($total < 100) {
            $this->newLine();
            $this->warn('樣本太少（<100 次搜尋），這個排名還不足以拿來改 B3 的三個常駐項目——先讓它累積。');
        }

        $this->newLine();
        $this->line('搭配 `php artisan search:misses` 的「篩選分佈」一起看：常駐的條件是常被用、且不常把結果篩成 0 家。');

        return self::SUCCESS;
    }
}
