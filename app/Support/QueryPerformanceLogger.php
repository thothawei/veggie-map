<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

/**
 * 慢查詢記錄（總 Prompt 第三十五節的「至少」清單裡最後一項）。
 *
 * 為什麼是應用層的 `DB::listen` 而不是 MySQL 的 slow query log：後者要有伺服器
 * 存取權才看得到、而且沒有辦法把「是哪一個端點打的」關聯進去。應用層記得到
 * route，排查時才知道要去改哪一支查詢。兩者不衝突，正式環境兩個都開最好。
 *
 * **不記 bindings**：搜尋條件裡有使用者打的關鍵字與座標，那是個人資料
 * （跟 LogSlowApiRequests 不記 query string 是同一個理由）。SQL 樣板本身不含資料。
 */
final class QueryPerformanceLogger
{
    public static function handle(QueryExecuted $event): void
    {
        $threshold = (int) config('veggiemap.observability.slow_query_ms', 200);

        if ($event->time < $threshold) {
            return;
        }

        Log::warning('Slow database query', [
            'connection' => $event->connectionName,
            'duration_ms' => round($event->time, 2),
            // SQL 樣板（`?` 還沒被代入），所以不含使用者資料。
            'sql' => self::truncate($event->sql),
            // request() 在 console（artisan、queue worker）裡仍然存在，但沒有 route。
            'route' => request()->route()?->uri() ?? request()->path(),
        ]);
    }

    /**
     * 很長的 SQL（例如相關性排序那串 CASE）會把 log 撐爆，而且看前面就夠認出來了。
     */
    private static function truncate(string $sql): string
    {
        $limit = 500;

        return mb_strlen($sql) > $limit ? mb_substr($sql, 0, $limit).'…' : $sql;
    }
}
