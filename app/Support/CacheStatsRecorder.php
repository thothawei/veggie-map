<?php

namespace App\Support;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;

/**
 * Cache 命中率，**分 key family** 統計（總 Prompt 第三十五節）。
 *
 * Redis 的 `INFO stats` 只有全域的 keyspace_hits／misses，混了 session、rate limit、
 * queue 等所有東西在一起，看不出「搜尋快取到底有沒有用」。這裡按 key 的家族
 * （`restaurants:search`／`restaurant:{id}`／`restaurants:suggest`／`geocode`）分開記。
 *
 * 計數器存在 cache 本身、按日切、附 TTL：不需要新資料表，也不會無限成長。
 * 這是取樣統計不是稽核紀錄——重啟 Redis 就歸零，那可以接受。
 *
 * **不記完整的 key**：`restaurants:search:{md5}` 的 hash 是使用者查詢條件算出來的，
 * 逐個記等於記下每一次搜尋，而且會產生幾萬個 key。只記家族名稱。
 */
final class CacheStatsRecorder
{
    /** 計數器保留天數。看命中率不需要歷史，兩天足夠涵蓋「昨天到今天」。 */
    private const TTL_SECONDS = 172800;

    public static function hit(CacheHit $event): void
    {
        self::record($event->key, 'hit');
    }

    public static function missed(CacheMissed $event): void
    {
        self::record($event->key, 'miss');
    }

    /**
     * @return array<string, array{hit: int, miss: int, ratio: float|null}>
     */
    public static function snapshot(?string $day = null): array
    {
        $day ??= now()->toDateString();
        $stats = [];

        foreach (self::families() as $family) {
            $hit = (int) Cache::get(self::key($family, 'hit', $day), 0);
            $miss = (int) Cache::get(self::key($family, 'miss', $day), 0);
            $total = $hit + $miss;

            $stats[$family] = [
                'hit' => $hit,
                'miss' => $miss,
                // 沒有樣本時是 null 而不是 0——「這段時間沒人查」跟「命中率 0%」
                // 是兩件事，印成 0% 會讓人以為快取壞了。
                'ratio' => $total > 0 ? round($hit / $total, 4) : null,
            ];
        }

        return $stats;
    }

    /**
     * key 家族。順序決定比對優先權：`restaurants:search` 必須排在 `restaurant` 前面，
     * 否則前綴比對會先中後者。
     *
     * @return list<string>
     */
    public static function families(): array
    {
        return ['restaurants:search', 'restaurants:suggest', 'restaurant', 'geocode'];
    }

    private static function record(string $key, string $result): void
    {
        $family = self::familyFor($key);

        if ($family === null) {
            return;
        }

        $cacheKey = self::key($family, $result, now()->toDateString());

        // add() 先建立帶 TTL 的 0，再 increment——直接 increment 一個不存在的 key
        // 會建立**沒有 TTL** 的計數器，那就會永遠留在 Redis 裡。
        Cache::add($cacheKey, 0, self::TTL_SECONDS);
        Cache::increment($cacheKey);
    }

    private static function familyFor(string $key): ?string
    {
        foreach (self::families() as $family) {
            if (str_starts_with($key, $family)) {
                return $family;
            }
        }

        return null;
    }

    private static function key(string $family, string $result, string $day): string
    {
        return "cache-stats:{$day}:{$family}:{$result}";
    }
}
