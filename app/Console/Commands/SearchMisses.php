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
 *
 * 也回答 B3／B4 的「哪三個是常駐 quick filter」——那三個目前是規劃暫定的，
 * 不是量出來的（見 FilterDrawer 的 quickConfidence 註解）。輸出裡的「篩選
 * 分佈」印出「哪個篩選鍵最常跟零結果一起出現」的排行榜，累積到真實流量後
 * 才有數字可以回頭調整那三個是誰。
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

        $this->printFilterBreakdown($since);

        return self::SUCCESS;
    }

    /**
     * B3／B4 的「哪三個是常駐 quick filter」——那三個目前是規劃暫定的，不是
     * 量出來的。這裡列出「哪個篩選鍵最常跟零結果一起出現」，累積到真實流量
     * 後才有數字可以回頭調整。JSON 欄位的聚合在 PHP 端算——miss 本來就是
     * 少數事件（跟主查詢比），量不到需要在 SQL 端做 JSON_TABLE 聚合的規模，
     * 拉回來直接數比較不用管 MySQL 版本支不支援。
     */
    private function printFilterBreakdown(Carbon $since): void
    {
        $rows = SearchMiss::query()
            ->where('created_at', '>=', $since)
            ->where('had_filters', true)
            ->pluck('active_filters');

        if (count($rows) === 0) {
            $this->line('這段期間沒有「有開篩選」的零結果查詢，還沒有資料可以回答哪三個該常駐。');

            return;
        }

        $tally = [];

        /** @var list<string>|null $keys */
        foreach ($rows as $keys) {
            foreach ($keys ?? [] as $key) {
                $tally[$key] = ($tally[$key] ?? 0) + 1;
            }
        }

        arsort($tally);

        $this->newLine();
        $this->info('篩選分佈（哪個篩選鍵最常跟零結果一起出現，依此決定 B3 常駐 quick filter 的候選）：');
        $this->table(
            ['篩選鍵', '出現次數'],
            collect($tally)->map(fn (int $count, string $key) => [$key, $count])->values()->all(),
        );
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
