<?php

namespace App\Services\External;

use App\Models\ExternalApiLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Overpass API（overpass-api.de），僅用於 `restaurants:sync` 離線批次匯入，不掛在使用者
 * 請求路徑上（見 docs/external-apis.md）。429 時依官方建議退避，所有呼叫寫 ExternalApiLog
 * （不記任何 key——本來就沒有 key）。
 */
class OsmRestaurantProvider implements RestaurantProviderInterface
{
    private const AMENITIES = ['restaurant', 'cafe'];

    /**
     * 只收「純素食店」——OSM 的 diet:* 標籤裡 `only` 代表整間店都是素／純素，`yes` 只代表
     * 「有素食選項」的一般餐廳（2026-08-25 產品決定：後者不收）。這個篩選一定要下在
     * Overpass 查詢裡，不能只在 PHP 端過濾：台北市 bbox 下 restaurant|cafe 共 15,974 個
     * 節點，其中 vegetarian=only ∪ vegan=only 只有 222 個，不篩等於每次同步都把整個城市的
     * 餐廳搬回來。
     */
    private const DIET_ONLY_TAGS = ['diet:vegetarian', 'diet:vegan'];

    public function fetch(BoundingBox $bbox): array
    {
        $query = $this->buildQuery($bbox);
        $url = config('services.overpass.url');
        $timeout = (int) config('services.overpass.timeout', 30);

        $startedAt = microtime(true);
        $success = false;
        $status = 0;
        $errorCode = null;

        try {
            $response = Http::timeout($timeout)
                // 沒有這個 header 會被 overpass-api.de 以 HTTP 406 擋掉（Guzzle 預設 UA
                // 進不去），而且 retry() 會把它包成 RequestException 丟出來，最後靜默回
                // 空陣列——同步看起來成功但一筆都沒有。2026-08-25 實測，見 docs/progress.md。
                ->withHeaders(['User-Agent' => (string) config('services.overpass.user_agent')])
                ->retry(3, 30000, function ($exception, $request) {
                    return $exception instanceof RequestException
                        && $exception->response->status() === 429;
                })
                ->asForm()
                ->post($url, ['data' => $query]);

            $status = $response->status();
            $success = $response->successful();

            if (! $success) {
                $errorCode = 'HTTP_'.$status;

                return [];
            }

            return $this->parseElements($response->json('elements', []));
        } catch (RequestException $e) {
            // retry() 預設會對失敗回應 throw，所以非 429 的 HTTP 錯誤都走這條而不是上面
            // 的 `if (! $success)`。要把真實狀態碼記進 log，不然排查時只看得到 status=0
            // 跟一個沒有資訊量的 RequestException。
            $status = $e->response->status();
            $errorCode = 'HTTP_'.$status;

            return [];
        } catch (\Throwable $e) {
            $errorCode = substr(class_basename($e), 0, 100);

            return [];
        } finally {
            ExternalApiLog::create([
                'provider' => 'overpass',
                'endpoint' => '/api/interpreter',
                'status' => $status,
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'success' => $success,
                'error_code' => $errorCode,
            ]);
        }
    }

    public function sourceName(): string
    {
        return 'osm';
    }

    private function buildQuery(BoundingBox $bbox): string
    {
        $box = "{$bbox->minLat},{$bbox->minLng},{$bbox->maxLat},{$bbox->maxLng}";
        $amenities = implode('|', self::AMENITIES);

        // 兩個 diet 標籤各一條 statement 包成 union——Overpass QL 的多個 [tag] 是 AND，
        // 要「vegetarian=only 或 vegan=only」只能靠 (...); union，不能寫成單一 statement。
        $clauses = implode("\n", array_map(
            fn (string $tag) => "  node[\"amenity\"~\"^({$amenities})$\"][\"{$tag}\"=\"only\"]({$box});",
            self::DIET_ONLY_TAGS,
        ));

        return <<<OVERPASS_QL
            [out:json][timeout:25];
            (
            {$clauses}
            );
            out body;
            OVERPASS_QL;
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return RestaurantData[]
     */
    private function parseElements(array $elements): array
    {
        $dietTagMap = [
            'diet:vegan' => 'vegan',
            'diet:vegetarian' => 'vegetarian',
        ];

        $result = [];

        foreach ($elements as $node) {
            $tags = $node['tags'] ?? [];

            if (empty($tags['name']) || ! isset($node['lat'], $node['lon'])) {
                continue;
            }

            $dietCodes = [];
            foreach ($dietTagMap as $tag => $code) {
                if (in_array($tags[$tag] ?? null, ['yes', 'only'], true)) {
                    $dietCodes[] = $code;
                }
            }

            $result[] = new RestaurantData(
                sourceId: (string) $node['id'],
                name: $tags['name'],
                latitude: (float) $node['lat'],
                longitude: (float) $node['lon'],
                address: $this->buildAddress($tags),
                city: $tags['addr:city'] ?? null,
                district: $tags['addr:district'] ?? null,
                phone: $tags['phone'] ?? $tags['contact:phone'] ?? null,
                website: $tags['website'] ?? $tags['contact:website'] ?? null,
                dietCodes: $dietCodes,
            );
        }

        return $result;
    }

    private function buildAddress(array $tags): ?string
    {
        $parts = array_filter([
            $tags['addr:street'] ?? null,
            $tags['addr:housenumber'] ?? null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
