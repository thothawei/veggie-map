<?php

namespace App\Services\External;

use App\Models\ExternalApiLog;
use App\Support\DietCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Overpass API（overpass-api.de），僅用於 `restaurants:sync` 離線批次匯入，不掛在使用者
 * 請求路徑上（見 docs/external-apis.md）。429 時依官方建議退避，所有呼叫寫 ExternalApiLog
 * （不記任何 key——本來就沒有 key）。
 */
class OsmRestaurantProvider implements RestaurantProviderInterface
{
    private readonly string $dietMode;

    /**
     * 收錄規則名稱來自 config/diet.php 的 sync_modes，跟著 bbox 走（EXTERNAL_API_SYNC_BBOXES），
     * 不在這裡寫死國家。篩選必須下在 Overpass 查詢裡：不篩的話台北市 bbox 會回上萬個節點。
     */
    public function __construct(?string $dietMode = null)
    {
        $this->dietMode = DietCatalog::resolveSyncMode($dietMode);
    }

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
        $amenities = implode('|', DietCatalog::osmAmenities());

        // 每個 diet tag 一條 statement 包成 union——Overpass QL 的多個 [tag] 是 AND，
        // 要 OR 只能靠 (...); union。
        $clauses = [];

        foreach (DietCatalog::osmTags() as $tag) {
            $filter = $this->dietFilter($tag);

            if ($filter === null) {
                continue;
            }

            $clauses[] = "  node[\"amenity\"~\"^({$amenities})$\"]{$filter}({$box});";
        }

        $clauseBlock = implode("\n", $clauses);

        return <<<OVERPASS_QL
            [out:json][timeout:25];
            (
            {$clauseBlock}
            );
            out body;
            OVERPASS_QL;
    }

    /**
     * 單一值用精確比對；多個值用 regex。值的清單來自 config（sync mode ∩ 該 tag 的 osm_values）。
     */
    private function dietFilter(string $tag): ?string
    {
        $values = DietCatalog::osmValuesForTagInMode($tag, $this->dietMode);

        if ($values === []) {
            return null;
        }

        if (count($values) === 1) {
            return "[\"{$tag}\"=\"{$values[0]}\"]";
        }

        $pattern = implode('|', $values);

        return "[\"{$tag}\"~\"^({$pattern})$\"]";
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     * @return RestaurantData[]
     */
    private function parseElements(array $elements): array
    {
        $result = [];

        foreach ($elements as $node) {
            $tags = $node['tags'] ?? [];

            if (empty($tags['name']) || ! isset($node['lat'], $node['lon'])) {
                continue;
            }

            $dietCodes = DietCatalog::mapOsmTags($tags);

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
                featureCodes: $this->featureCodes($tags),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $tags
     * @return string[]
     */
    private function featureCodes(array $tags): array
    {
        $codes = [];

        foreach (DietCatalog::osmFeatureMap() as $tag => $mapping) {
            if (in_array($tags[$tag] ?? null, $mapping['values'], true)) {
                $codes[] = $mapping['feature'];
            }
        }

        return $codes;
    }

    private function buildAddress(array $tags): ?string
    {
        $parts = array_filter([
            $tags['addr:street'] ?? null,
            $tags['addr:housenumber'] ?? null,
        ]);

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        $full = trim((string) ($tags['addr:full'] ?? ''));

        return $full === '' ? null : $full;
    }
}
