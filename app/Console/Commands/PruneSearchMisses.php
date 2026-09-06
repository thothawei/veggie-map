<?php

namespace App\Console\Commands;

use App\Models\SearchMiss;
use Illuminate\Console\Command;

/**
 * 清掉超過保留期限的零結果查詢紀錄（A8）。這張表故意不記任何身分資訊，
 * 但關鍵字本身可能含地名之類的內容，沒有理由無限期留著。
 */
class PruneSearchMisses extends Command
{
    protected $signature = 'search-misses:prune {--days=90 : 保留天數，超過這個天數的紀錄會被刪除}';

    protected $description = '刪除超過保留天數的零結果查詢紀錄';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = SearchMiss::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("刪除了 {$deleted} 筆超過 {$days} 天的零結果查詢紀錄。");

        return self::SUCCESS;
    }
}
