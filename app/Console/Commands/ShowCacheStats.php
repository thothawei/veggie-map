<?php

namespace App\Console\Commands;

use App\Support\CacheStatsRecorder;
use Illuminate\Console\Command;

class ShowCacheStats extends Command
{
    protected $signature = 'cache:stats {--day= : YYYY-MM-DD，預設今天}';

    protected $description = '各 cache key family 今日的命中率（見 docs/observability.md）';

    public function handle(): int
    {
        $day = $this->option('day') ?: now()->toDateString();
        $rows = [];

        foreach (CacheStatsRecorder::snapshot($day) as $family => $stats) {
            $rows[] = [
                $family,
                $stats['hit'],
                $stats['miss'],
                // 沒有樣本時印「—」而不是 0%：「這段時間沒人查」跟「命中率 0%」
                // 是兩件事。
                $stats['ratio'] === null ? '—' : round($stats['ratio'] * 100, 1).'%',
            ];
        }

        $this->info("Cache 命中率（{$day}）");
        $this->table(['key family', 'hit', 'miss', '命中率'], $rows);

        return self::SUCCESS;
    }
}
