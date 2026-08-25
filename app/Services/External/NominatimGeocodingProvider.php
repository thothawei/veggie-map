<?php

namespace App\Services\External;

use App\Models\ExternalApiLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Nominatim（OSM 官方地理編碼），僅供使用者主動搜尋地點使用（見 docs/external-apis.md：
 * 每秒最多 1 次請求、必須帶合法 User-Agent）。呼叫端（GeocodeController）另外用 Redis
 * cache 擋掉重複查詢字串，這裡只負責單次呼叫本身的 timeout／重試／記錄。
 */
class NominatimGeocodingProvider implements GeocodingProviderInterface
{
    private const RESULT_LIMIT = 5;

    public function search(string $query): array
    {
        $breaker = CircuitBreaker::for('nominatim');

        // 這條在使用者請求路徑上（GET /geocode），斷路的價值比同步更高：Nominatim
        // 掛掉時每個搜尋都要等滿 5 秒逾時×3 次重試才回錯，使用者體感是整個網站卡住。
        // 開路期間直接丟 GeocodingUnavailableException——呼叫端已經會把它轉成
        // 「搜尋地點暫時無法使用」，不是 500。
        if (! $breaker->available()) {
            ExternalApiLog::create([
                'provider' => 'nominatim',
                'endpoint' => '/search',
                'status' => 0,
                'response_time_ms' => 0,
                'success' => false,
                'error_code' => 'CIRCUIT_OPEN',
            ]);

            throw new GeocodingUnavailableException('Nominatim circuit is open');
        }

        $url = rtrim(config('services.nominatim.url'), '/').'/search';
        $userAgent = config('services.nominatim.user_agent');

        $startedAt = microtime(true);
        $success = false;
        $status = 0;
        $errorCode = null;

        try {
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(5)
                ->retry(2, 1000, function ($exception) {
                    return $exception instanceof RequestException
                        && $exception->response->status() === 429;
                }, throw: false)
                ->get($url, [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => self::RESULT_LIMIT,
                ]);

            $status = $response->status();
            $success = $response->successful();

            if (! $success) {
                $errorCode = 'HTTP_'.$status;

                throw new GeocodingUnavailableException('Nominatim returned HTTP '.$status);
            }

            return $this->parseResults($response->json() ?? []);
        } catch (GeocodingUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $errorCode = substr(class_basename($e), 0, 100);

            throw new GeocodingUnavailableException($e->getMessage(), 0, $e);
        } finally {
            $success ? $breaker->recordSuccess() : $breaker->recordFailure();

            ExternalApiLog::create([
                'provider' => 'nominatim',
                'endpoint' => '/search',
                'status' => $status,
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'success' => $success,
                'error_code' => $errorCode,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return GeocodedPlace[]
     */
    private function parseResults(array $results): array
    {
        $places = [];

        foreach ($results as $result) {
            if (! isset($result['display_name'], $result['lat'], $result['lon'])) {
                continue;
            }

            $places[] = new GeocodedPlace(
                displayName: $result['display_name'],
                latitude: (float) $result['lat'],
                longitude: (float) $result['lon'],
            );
        }

        return $places;
    }
}
