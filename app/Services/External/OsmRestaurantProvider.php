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

    private const DIET_TAGS = ['diet:vegetarian', 'diet:vegan'];

    /** 只收整間店都是素／純素的（diet:*=only）。 */
    public const DIET_ONLY = 'only';

    /** 連「有素食選項」的一般餐廳一起收（diet:*=yes|only）。 */
    public const DIET_YES = 'yes';

    public const DIET_MODES = [self::DIET_ONLY, self::DIET_YES];

    /**
     * OSM 標籤 → features.code 的對應，以及**每個標籤要收哪些值**。
     *
     * 值的白名單是重點，不能寫成「有這個標籤就算有這個特色」：2026-08-25 實測台中 177 筆＋
     * 東京 210 筆的標籤分布，`outdoor_seating=no` 有 32 筆、比 `yes` 的 10 筆還多，
     * `wheelchair=no` 14 筆、`delivery=no` 5 筆。只看 key 存在會把明確標示「沒有」的店
     * 標成「有」——這種錯比漏收嚴重得多，使用者會白跑一趟。
     *
     * 沒有列在這裡的特色是查證後確認 OSM 沒有可用標籤：`parking` 與 `family_friendly`
     * 在兩地共 387 筆節點裡是 0 筆（含 `capacity:parking`／`kids_area` 等變體都沒有）。
     * 寧可讓那兩個篩選維持空的，也不硬湊一個不成立的對應。
     *
     * @var array<string, array{feature: string, values: string[]}>
     */
    private const FEATURE_TAG_MAP = [
        'takeaway' => ['feature' => 'takeout', 'values' => ['yes', 'only']],
        'delivery' => ['feature' => 'delivery', 'values' => ['yes']],
        // patio／veranda／terrace 這類值本身就代表「有戶外座位」，只是講明是哪一種。
        'outdoor_seating' => ['feature' => 'outdoor_seating', 'values' => [
            'yes', 'patio', 'veranda', 'terrace', 'garden', 'rooftop', 'sidewalk', 'street', 'pedestrian_zone',
        ]],
        // internet_access=yes 理論上可能是有線，但在餐廳／咖啡店的實務標註幾乎都是 wifi。
        'internet_access' => ['feature' => 'wifi', 'values' => ['wlan', 'yes']],
        'reservation' => ['feature' => 'reservation', 'values' => ['yes', 'required', 'recommended']],
        'dog' => ['feature' => 'pet_friendly', 'values' => ['yes', 'leashed']],
    ];

    /**
     * 收錄規則依國別而異，因為 OSM 標籤慣例不同（2026-08-25 實測）：台中市 177/220 家標
     * `only`（80%），東京 23 区只有 46/210（22%），日本社群慣用 `yes`。套同一套規則會讓
     * 其中一邊失真，所以規則跟著同步範圍走，見 config/services.php 的 sync_regions。
     *
     * 不論哪種規則，篩選都必須下在 Overpass 查詢裡而不是 PHP 端：不篩的話台北市 bbox 會
     * 回 15,974 個節點（篩完 222 個），等於每次同步都把整個城市的餐廳搬回來。
     */
    public function __construct(private readonly string $dietMode = self::DIET_ONLY)
    {
        if (! in_array($dietMode, self::DIET_MODES, true)) {
            throw new \InvalidArgumentException(
                "Unknown diet mode [{$dietMode}], expected ".implode(' or ', self::DIET_MODES).'.'
            );
        }
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
        $amenities = implode('|', self::AMENITIES);

        // 兩個 diet 標籤各一條 statement 包成 union——Overpass QL 的多個 [tag] 是 AND，
        // 要「vegetarian 或 vegan」只能靠 (...); union，不能寫成單一 statement。
        $clauses = implode("\n", array_map(
            fn (string $tag) => "  node[\"amenity\"~\"^({$amenities})$\"]{$this->dietFilter($tag)}({$box});",
            self::DIET_TAGS,
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
     * `only` 模式用精確比對；`yes` 模式要同時涵蓋 yes 與 only——純素食店本來就該收進
     * 「有素食選項」這個較寬的集合，只寫 ="yes" 反而會把純素食店漏掉。
     */
    private function dietFilter(string $tag): string
    {
        return $this->dietMode === self::DIET_ONLY
            ? "[\"{$tag}\"=\"only\"]"
            : "[\"{$tag}\"~\"^(yes|only)$\"]";
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

        foreach (self::FEATURE_TAG_MAP as $tag => $mapping) {
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

        return $parts === [] ? null : implode(' ', $parts);
    }
}
