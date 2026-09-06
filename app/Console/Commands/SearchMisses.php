<?php

namespace App\Console\Commands;

use App\Models\SearchMiss;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 列出最近哪些關鍵字搜不到東西（A8）。這個 repo 每一輪都是「先量再改」，
 * 唯獨同義詞表是憑猜的——這支指令補的就是這個缺口：下一輪要加哪些同義詞，
 * 從這裡的排行榜找答案，不用再猜。
 */
class SearchMisses extends Command
{
    protected $signature = 'search:misses
        {--since=7d : 回看多久，格式是數字加單位 h(小時)／d(天)／w(週)，例如 24h、7d、2w}
        {--limit=20 : 最多列出幾個詞}';

    protected $description = '列出最近零結果查詢的排行榜，作為下一輪同義詞表的依據';

    public function handle(): int
    {
        $since = $this->parseSince((string) $this->option('since'));

        if ($since === null) {
            $this->error('--since 格式錯誤，需要數字加單位 h／d／w，例如 24h、7d、2w。');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $totalMisses = SearchMiss::query()->where('created_at', '>=', $since)->count();

        if ($totalMisses === 0) {
            $this->info("從 {$since->diffForHumans()} 到現在沒有任何零結果查詢。");

            return self::SUCCESS;
        }

        // 沒有關鍵字的零結果（純瀏覽＋篩選篩成 0）不進「該加哪些同義詞」的排行榜——
        // 那種 miss 沒有詞可以拿去查字表，混進來只會稀釋真正有意義的資料。
        // `toBase()`：這是聚合查詢，回傳的欄位（misses、with_filters…）不是
        // SearchMiss 的屬性，用 query builder 拿 stdClass 回來，不假裝它是 Eloquent model。
        /** @var Collection<int, object{normalized: string, sample_keyword: string, misses: int, with_filters: int, last_seen: string}> $rows */
        $rows = SearchMiss::query()
            ->toBase()
            ->selectRaw('normalized, MAX(keyword) as sample_keyword, COUNT(*) as misses, SUM(had_filters) as with_filters, MAX(created_at) as last_seen')
            ->where('created_at', '>=', $since)
            ->whereNotNull('normalized')
            ->groupBy('normalized')
            ->orderByDesc('misses')
            ->limit($limit)
            ->get();

        $keywordless = $totalMisses - SearchMiss::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('normalized')
            ->count();

        $this->table(
            ['詞', '次數', '含其他篩選', '最近一次'],
            $rows->map(fn ($row) => [
                $row->sample_keyword,
                $row->misses,
                "{$row->with_filters}/{$row->misses}",
                Carbon::parse($row->last_seen)->diffForHumans(),
            ])->all(),
        );

        $this->info("從 {$since->diffForHumans()} 到現在共 {$totalMisses} 次零結果查詢。");

        if ($keywordless > 0) {
            $this->line("另有 {$keywordless} 次沒有下關鍵字（純篩選篩成 0 筆），不列入上面的詞排行榜。");
        }

        return self::SUCCESS;
    }

    /** "24h"／"7d"／"2w" → 那個時間點的 Carbon。格式不對回 null，不猜使用者的意思。 */
    private function parseSince(string $raw): ?Carbon
    {
        if (! preg_match('/^(\d+)([hdw])$/', trim($raw), $matches)) {
            return null;
        }

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            'h' => now()->subHours($amount),
            'd' => now()->subDays($amount),
            'w' => now()->subWeeks($amount),
        };
    }
}
