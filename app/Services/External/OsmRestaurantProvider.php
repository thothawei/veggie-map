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

        return <<<OVERPASS_QL
            [out:json][timeout:25];
            node["amenity"~"^({$amenities})\$"]({$box});
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
