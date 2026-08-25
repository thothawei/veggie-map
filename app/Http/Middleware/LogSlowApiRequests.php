<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 請求的 response time（總 Prompt 第三十五節的「至少」清單）。
 *
 * 為什麼只記慢的、不是每一筆：每一筆都寫 log，在有流量時等於自製一個成本很高
 * 又沒人看的 APM——而這個專案沒有 log 聚合服務去消化它。門檻以上才記，log 裡
 * 出現的東西就都是值得看的。門檻設在 config，正式環境要收緊就改設定。
 *
 * 每一筆請求都會在回應加上 `X-Response-Time-Ms` 標頭：不管快慢都量得到，
 * 壓測與手動排查不必先去翻 log。
 *
 * 刻意不寫進資料表：那需要一張會無限成長的表與清理排程。這裡先用 Laravel 的
 * structured log，之後要接 Pulse／APM 也是換這一層。
 */
class LogSlowApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $response->headers->set('X-Response-Time-Ms', (string) $durationMs);

        $threshold = (int) config('veggiemap.observability.slow_request_ms', 1000);

        if ($durationMs >= $threshold) {
            Log::warning('Slow API request', [
                'method' => $request->method(),
                // route()->uri() 而不是完整網址：`/restaurants/{restaurant}` 這種樣板
                // 才聚合得起來，逐筆 id 只會變成幾千個獨一無二的字串。
                'route' => $request->route()?->uri() ?? $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                // query string 會帶使用者搜尋的關鍵字與座標，屬於個人資料，不記。
                // 需要重現時用 route 樣板加上時間點去找。
            ]);
        }

        return $response;
    }
}
