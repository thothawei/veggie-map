<?php

namespace App\Services\External;

use App\Models\ExternalApiLog;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 用 OpenStreetMap 判斷永久歇業。免費、不需要金鑰，所以這是預設 provider。
 *
 * OSM 對歇業的慣例是給主要標籤加前綴：`amenity=restaurant` 變成
 * `disused:amenity=restaurant`（暫停營業但建物還在）或 `was:amenity=restaurant`
 * （已經不是這個用途了）。這是製圖者明確表達的判斷，拿來當自動下架的依據夠硬。
 *
 * **節點消失不算歇業**。查不到的節點回 Unknown 而不是 ClosedPermanently：
 * 一個 node 會因為被合併進 way／building、被改成別的 element、或單純被誤刪而消失，
 * 那些都不代表店收了。把「查不到」當成歇業，等於讓 OSM 的任何一次結構調整
 * 都能從我們的地圖上抹掉一家還在營業的店。
 */
class OverpassBusinessStatusProvider implements BusinessStatusProviderInterface
{
    /** 一次問幾個 node。Overpass 對單一請求的長度與執行時間都有限制。 */
    private const CHUNK = 200;

    public function name(): string
    {
        return 'overpass';
    }

    public function statusFor(iterable $restaurants): array
    {
        /** @var array<int, Restaurant> $byNodeId */
        $byNodeId = [];

        foreach ($restaurants as $restaurant) {
            // 只有 OSM 匯入的店有 node id 可查。手動建立的店這個 provider 無從判斷。
            if ($restaurant->source !== 'osm' || ! ctype_digit((string) $restaurant->source_id)) {
                continue;
            }

            $byNodeId[(int) $restaurant->source_id] = $restaurant;
        }

        if ($byNodeId === []) {
            return [];
        }

        $statuses = [];

        foreach (array_chunk(array_keys($byNodeId), self::CHUNK) as $nodeIds) {
            foreach ($this->fetchChunk($nodeIds) as $nodeId => $status) {
                $restaurant = $byNodeId[$nodeId] ?? null;

                if ($restaurant !== null) {
                    $statuses[$restaurant->id] = $status;
                }
            }
        }

        return $statuses;
    }

    /**
     * @param  list<int>  $nodeIds
     * @return array<int, BusinessStatus>
     */
    private function fetchChunk(array $nodeIds): array
    {
        $breaker = CircuitBreaker::for('overpass');

        if (! $breaker->available()) {
            Log::warning('Overpass circuit is open, skipping business status check', [
                'retry_after_seconds' => $breaker->retryAfter(),
                'nodes' => count($nodeIds),
            ]);

            $this->log(0, 0, false, 'CIRCUIT_OPEN');

            return [];
        }

        // `out tags;` 只要標籤不要座標——這裡的問題是「還是不是餐廳」，
        // 不需要幾何資料，回應小一個數量級。
        $query = '[out:json][timeout:'.(int) config('services.overpass.timeout', 30).'];'
            .'node(id:'.implode(',', $nodeIds).');out tags;';

        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('services.overpass.timeout', 30))
                // 缺這個 header 會被 overpass-api.de 用 406 擋掉（同 OsmRestaurantProvider）。
                ->withHeaders(['User-Agent' => (string) config('services.overpass.user_agent')])
                ->asForm()
                ->post((string) config('services.overpass.url'), ['data' => $query]);

            $elapsed = (int) ((microtime(true) - $startedAt) * 1000);

            if (! $response->successful()) {
                $breaker->recordFailure();
                $this->log($response->status(), $elapsed, false, 'HTTP_'.$response->status());

                return [];
            }

            $breaker->recordSuccess();
            $this->log($response->status(), $elapsed, true, null);

            return $this->interpret($response->json('elements') ?? [], $nodeIds);
        } catch (Throwable $e) {
            $breaker->recordFailure();
            $this->log(0, (int) ((microtime(true) - $startedAt) * 1000), false, 'EXCEPTION');

            Log::warning('Overpass business status check failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, array{id?: int, tags?: array<string, string>}>  $elements
     * @param  list<int>  $requested
     * @return array<int, BusinessStatus>
     */
    private function interpret(array $elements, array $requested): array
    {
        $statuses = [];

        foreach ($elements as $element) {
            $id = $element['id'] ?? null;

            if ($id === null) {
                continue;
            }

            $tags = $element['tags'] ?? [];
            $statuses[(int) $id] = $this->fromTags($tags);
        }

        /*
         * 請求了但沒回來的 node = Missing（不是 Unknown，也不是歇業）。
         *
         * 這一行只在 HTTP 請求成功時才會跑到——失敗的路徑直接回空陣列，
         * 呼叫端看到的是「這批沒查到狀態」。這個分野很重要：Overpass 掛掉
         * 不該讓一千多家店同時冒出「疑似歇業」。
         */
        foreach ($requested as $nodeId) {
            $statuses[$nodeId] ??= BusinessStatus::Missing;
        }

        return $statuses;
    }

    /**
     * 現行業態標籤。有其中任何一個，就表示這個點位現在仍然是一間營業中的店。
     *
     * @var list<string>
     */
    private const LIVE_VENUE_TAGS = ['amenity', 'shop', 'office', 'craft', 'tourism', 'leisure'];

    /**
     * 只有「有 disused:／was: 前綴」**而且**「沒有現行業態標籤」才算永久歇業。
     *
     * 後半段是實測補上的，不是預防性設計：2026-08-27 查台北市中心的
     * `disused:amenity=restaurant` 節點，三個裡有兩個同時帶著現行的 `amenity`
     * ——node 299002404 的 `disused:name` 是 N.Y.Bagels Cafe，但 `name` 是「初泰」。
     * 那是舊店收了、新店進駐同一個點位，店還在營業。
     *
     * 少了這個條件，我們會把換過手的店全部從地圖上下架，而且下架的正好是
     * 「地址還在、生意還在做」的那些。
     *
     * @param  array<string, string>  $tags
     */
    private function fromTags(array $tags): BusinessStatus
    {
        $hasDisusedPrefix = false;

        foreach (array_keys($tags) as $key) {
            if (str_starts_with($key, 'disused:') || str_starts_with($key, 'was:')) {
                $hasDisusedPrefix = true;

                break;
            }
        }

        if (! $hasDisusedPrefix) {
            return BusinessStatus::Operational;
        }

        foreach (self::LIVE_VENUE_TAGS as $tag) {
            if (isset($tags[$tag])) {
                // 舊用途歇業，但這個點位現在是別的店（或同一家換了業態）。
                return BusinessStatus::Operational;
            }
        }

        return BusinessStatus::ClosedPermanently;
    }

    private function log(int $status, int $elapsedMs, bool $success, ?string $errorCode): void
    {
        ExternalApiLog::create([
            'provider' => 'overpass',
            'endpoint' => '/api/interpreter (business status)',
            'status' => $status,
            'response_time_ms' => $elapsedMs,
            'success' => $success,
            'error_code' => $errorCode,
        ]);
    }
}
