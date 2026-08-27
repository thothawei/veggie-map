<?php

namespace App\Services\External;

use App\Models\ExternalApiLog;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Google Places 的 `business_status`——使用者在 Google 地圖上看到的「永久歇業」
 * 就是這個欄位（`CLOSED_PERMANENTLY`）。
 *
 * **要付費帳號與 API key**，所以不是預設 provider（專案原則：優先用免費、
 * 不需要付費帳號的來源）。沒有 key 時建構就丟例外，不靜默退回別的來源——
 * 設定成 google 卻悄悄用 OSM 在跑，是最難察覺的那種錯。
 *
 * 沒有官方的「用座標查一批」端點，所以這裡逐家查 Text Search（費用按請求計）。
 * 這也是為什麼指令預設有 --limit：一次掃一千多家會直接產生一千多次計費請求。
 *
 * 找不到對應地點時回 Unknown 而不是歇業：Google 沒收錄 ≠ 店收了，
 * 小店與新店本來就常常查不到。
 */
class GooglePlacesBusinessStatusProvider implements BusinessStatusProviderInterface
{
    private readonly string $apiKey;

    public function __construct()
    {
        $key = (string) config('services.google_places.key', '');

        if ($key === '') {
            throw new RuntimeException(
                'GOOGLE_PLACES_API_KEY 沒有設定。'
                .'把 BUSINESS_STATUS_PROVIDER 改回 overpass，或填入金鑰後再跑。'
            );
        }

        $this->apiKey = $key;
    }

    public function name(): string
    {
        return 'google_places';
    }

    public function statusFor(iterable $restaurants): array
    {
        $statuses = [];

        foreach ($restaurants as $restaurant) {
            $status = $this->lookup($restaurant);

            if ($status !== null) {
                $statuses[$restaurant->id] = $status;
            }
        }

        return $statuses;
    }

    private function lookup(Restaurant $restaurant): ?BusinessStatus
    {
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('services.google_places.timeout', 15))
                ->withHeaders([
                    // 金鑰走 header 不走查詢字串：查詢字串會進 access log 與轉址紀錄。
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => 'places.businessStatus,places.displayName',
                ])
                ->post('https://places.googleapis.com/v1/places:searchText', [
                    'textQuery' => trim($restaurant->name.' '.($restaurant->address ?? '')),
                    'locationBias' => [
                        'circle' => [
                            'center' => [
                                'latitude' => $restaurant->latitude,
                                'longitude' => $restaurant->longitude,
                            ],
                            'radius' => 100.0,
                        ],
                    ],
                    'maxResultCount' => 1,
                ]);

            $elapsed = (int) ((microtime(true) - $startedAt) * 1000);

            if (! $response->successful()) {
                $this->log($response->status(), $elapsed, false, 'HTTP_'.$response->status());

                return null;
            }

            $this->log($response->status(), $elapsed, true, null);

            $businessStatus = $response->json('places.0.businessStatus');

            return match ($businessStatus) {
                'CLOSED_PERMANENTLY' => BusinessStatus::ClosedPermanently,
                'OPERATIONAL' => BusinessStatus::Operational,
                // CLOSED_TEMPORARILY 不算永久歇業：暫時歇業的店會再開，
                // 下架它等於把一家還存在的店從地圖上抹掉。
                default => BusinessStatus::Unknown,
            };
        } catch (Throwable $e) {
            $this->log(0, (int) ((microtime(true) - $startedAt) * 1000), false, 'EXCEPTION');
            Log::warning('Google Places business status lookup failed', [
                'restaurant_id' => $restaurant->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function log(int $status, int $elapsedMs, bool $success, ?string $errorCode): void
    {
        ExternalApiLog::create([
            'provider' => 'google_places',
            // 不記 textQuery：店名地址本身不是機密，但 endpoint 欄位是拿來看
            // 「打了哪支 API」的，塞進查詢內容只會讓這張表變得難讀。
            'endpoint' => '/v1/places:searchText',
            'status' => $status,
            'response_time_ms' => $elapsedMs,
            'success' => $success,
            'error_code' => $errorCode,
        ]);
    }
}
