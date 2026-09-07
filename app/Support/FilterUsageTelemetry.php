<?php

namespace App\Support;

use App\Models\Feature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 「哪個篩選最常被使用」的每日計數器（B4 的量體評估）。
 *
 * **為什麼不是直接看 `search:misses` 的篩選分佈**：那張表只在**零結果**時才寫入，
 * 它回答的是「哪個篩選最容易把結果篩成 0 家」——那恰恰是最**不**該常駐的候選。
 * B3 要決定的是「哪三個最常被按」，那需要**所有**搜尋的分佈，不是只有搜壞的那些。
 * 兩張表要一起看：常駐的條件是「常被用」且「不常把結果殺光」。
 *
 * 存在 cache、按日切、附 TTL：跟 CacheStatsRecorder 同一套做法，不需要新資料表，
 * 也不會無限成長。這是取樣統計不是稽核紀錄——重啟 Redis 就歸零，那可以接受
 * （要的是「哪個明顯比較多」的相對排名，不是精確的絕對值）。
 *
 * **只記鍵名，不記值**：跟 `search_misses` 一貫的原則一樣，這是產品訊號不是
 * 使用者追蹤——不記 IP、不記 user id、不記關鍵字、不記座標。
 */
final class FilterUsageTelemetry
{
    /**
     * 計數器保留天數。真實流量很稀疏（這個站現在幾乎沒有），要看得出排名差異
     * 得累積夠久，所以留比 CacheStatsRecorder（2 天）長得多的 100 天。
     */
    private const TTL_SECONDS = 8640000;

    /** 總搜尋次數的計數器鍵名。用底線開頭，跟真正的篩選鍵不會撞名。 */
    public const TOTAL = '__searches';

    /**
     * 會被計數的篩選鍵。跟 `RestaurantRepository::activeFilterKeys()` 是同一份
     * 清單——report() 得逐個鍵去 cache 撈（cache 沒有「列出所有 key」這種操作），
     * 所以清單必須是靜態可列舉的，不能只在記錄時才算得出來。
     *
     * @return list<string>
     */
    public static function trackedKeys(): array
    {
        return [
            'diet', DietCatalog::venueScopeParam(), 'price_level', 'confidence_min',
            'open_now', 'bbox', 'city', 'district', ...Feature::CODES,
        ];
    }

    /**
     * 記一次搜尋：總數加一，每個有開的篩選鍵各加一。
     *
     * @param  list<string>  $activeKeys
     */
    public static function record(array $activeKeys): void
    {
        $day = now()->toDateString();

        // 沒開任何篩選的搜尋也要記進總數——分母少了它，「這個篩選被用的比例」
        // 會被高估成「在有開篩選的人裡面的比例」，那是另一個問題的答案。
        self::bump(self::key($day, self::TOTAL));

        foreach (array_unique($activeKeys) as $key) {
            self::bump(self::key($day, $key));
        }
    }

    /**
     * 回看 N 天的累計。回傳 `['total' => int, 'counts' => [鍵 => 次數]]`，
     * counts 由多到少排序，次數 0 的鍵也留著——「這個篩選一次都沒人用」
     * 本身就是決定「它不該常駐」的證據，隱藏起來反而看不到。
     *
     * @return array{total: int, counts: array<string, int>}
     */
    public static function report(int $days): array
    {
        $days = max(1, $days);
        $counts = array_fill_keys(self::trackedKeys(), 0);
        $total = 0;

        for ($i = 0; $i < $days; $i++) {
            $day = Carbon::today()->subDays($i)->toDateString();
            $total += (int) Cache::get(self::key($day, self::TOTAL), 0);

            foreach ($counts as $key => $count) {
                $counts[$key] = $count + (int) Cache::get(self::key($day, $key), 0);
            }
        }

        arsort($counts);

        return ['total' => $total, 'counts' => $counts];
    }

    private static function bump(string $cacheKey): void
    {
        // add() 先建立帶 TTL 的 0，再 increment——直接 increment 一個不存在的 key
        // 會建立**沒有 TTL** 的計數器，那就會永遠留在 Redis 裡。
        Cache::add($cacheKey, 0, self::TTL_SECONDS);
        Cache::increment($cacheKey);
    }

    private static function key(string $day, string $name): string
    {
        return "filter-usage:{$day}:{$name}";
    }
}
