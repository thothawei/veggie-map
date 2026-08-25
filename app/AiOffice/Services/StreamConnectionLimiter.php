<?php

namespace App\AiOffice\Services;

use Illuminate\Support\Facades\Cache;

/**
 * 「SSE 長連線佔滿 PHP-FPM worker」是 implementation-plan 第 13 節列的風險。
 * 每個使用者同時只能開設定值那麼多條，超過直接 429——排隊等於同樣佔著 worker。
 *
 * 計數放 cache 並帶 TTL：worker 被 kill 導致 release() 沒跑到時，計數會自己過期，
 * 不會讓使用者永久被鎖在門外。TTL 取單條連線壽命的兩倍。
 */
class StreamConnectionLimiter
{
    private const PREFIX = 'ai-office:sse-connections:';

    /** @return bool 取得名額才回 true；false 代表已達上限。 */
    public function acquire(int $userId): bool
    {
        $max = (int) config('ai_office.events.max_connections_per_user', 3);

        if ($max <= 0) {
            return false;
        }

        $key = self::PREFIX.$userId;
        Cache::add($key, 0, $this->ttl());

        if (Cache::increment($key) > $max) {
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    public function release(int $userId): void
    {
        $key = self::PREFIX.$userId;

        if (Cache::get($key) === null) {
            return;
        }

        if (Cache::decrement($key) <= 0) {
            Cache::forget($key);
        }
    }

    private function ttl(): int
    {
        return max(30, 2 * (int) config('ai_office.events.max_duration_seconds', 60));
    }
}
