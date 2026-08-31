<?php

namespace App\Services\External;

use App\Models\ExternalApiLog;
use App\Support\CuisineCatalog;
use App\Support\DietCatalog;
use App\Support\TaiwanAddress;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $breaker = CircuitBreaker::for('overpass');

        // 斷路：連續失敗過門檻後，冷卻時間內不再空等。排程一次跑五個 bbox，
        // Overpass 掛掉時後面幾個城市會立刻回來，而不是各自 retry 三次。
        if (! $breaker->available()) {
            Log::warning('Overpass circuit is open, skipping sync fetch', [
                'retry_after_seconds' => $breaker->retryAfter(),
            ]);

            ExternalApiLog::create([
                'provider' => 'overpass',
                'endpoint' => '/api/interpreter',
                'status' => 0,
                'response_time_ms' => 0,
                'success' => false,
                'error_code' => 'CIRCUIT_OPEN',
            ]);

            return [];
        }

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
            // 斷路器只認「這次呼叫成不成功」，不管失敗原因是逾時、429 還是 500——
            // 對呼叫端來說結果一樣是拿不到資料。
            $success ? $breaker->recordSuccess() : $breaker->recordFailure();

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
                // 標籤存在但值是空字串的節點確實有。在來源這一層就正規化成 null，
                // 免得空字串一路寫進 DB——那是一個值，不是「沒有這個資訊」。
                // 「臺中市」與「台中市」是同一個城市的兩種寫法（台中 bbox 實測 88 : 29），
                // 不收斂的話篩選清單會長出兩個一模一樣的選項。日本的值不受影響。
                city: TaiwanAddress::normalizeName(self::tagOrNull($tags, 'addr:city')),
                district: TaiwanAddress::normalizeName(self::tagOrNull($tags, 'addr:district')),
                phone: $tags['phone'] ?? $tags['contact:phone'] ?? null,
                website: $tags['website'] ?? $tags['contact:website'] ?? null,
                openingHours: isset($tags['opening_hours']) ? (string) $tags['opening_hours'] : null,
                dietCodes: $dietCodes,
                featureCodes: $this->featureCodes($tags),
                cuisineCodes: CuisineCatalog::mapOsmCuisine(
                    isset($tags['cuisine']) ? (string) $tags['cuisine'] : null,
                ),
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

    /**
     * @param  array<string, mixed>  $tags
     */
    private static function tagOrNull(array $tags, string $key): ?string
    {
        $value = trim((string) ($tags[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private function buildAddress(array $tags): ?string
    {
        // 有完整地址就用它——路名＋門牌常常缺城市，地圖上看不出在哪。
        // 台灣的 addr:full 寫法很雜（郵遞區號開頭、夾里鄰、臺／台混用），
        // 交給 TaiwanAddress 收斂；日本的字串走過去不會有任何變動（實測 42 筆 0 變動）。
        $full = trim((string) ($tags['addr:full'] ?? ''));

        if ($full !== '') {
            return TaiwanAddress::tidy($full);
        }

        $locality = [];

        /*
         * 日本的地址沒有「街道名」，用的是「都道府県 → 市区町村 → 町名 → 丁目 →
         * 街区符号 → 号」。addr:street／addr:housenumber 那組在這裡幾乎派不上用場。
         *
         * 這不是對日本地址的推測：2026-08-27 查東京素食餐廳的 OSM 節點（取樣 51 筆），
         * addr:neighbourhood 10 筆、addr:block_number 10 筆、addr:province 9 筆，
         * 而 addr:full 只有 1 筆。原本只讀 city/district/suburb/place ＋ street，
         * 結果是 195 家東京餐廳只有 41 家有地址、38 家有 city——**核心欄位一個都沒讀**。
         *
         * province 放最前面（東京都／大阪府），順序跟台灣一樣是大到小，
         * 所以同一份組裝邏輯兩邊都成立。
         */
        foreach (['addr:province', 'addr:city', 'addr:district', 'addr:suburb', 'addr:quarter', 'addr:neighbourhood', 'addr:place'] as $key) {
            $value = trim((string) ($tags[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $joined = implode('', $locality);

            if ($joined === '' || ! str_contains($joined, $value)) {
                $locality[] = $value;
            }
        }

        /*
         * 台灣：路名 ＋ 門牌（「公益路 100」）。
         * 日本：街区符号 ＋ 号（「2-3」）——block_number 與 housenumber 之間用短橫線，
         * 那是日本地址的寫法，不是我們自己編的分隔符。
         */
        $blockNumber = trim((string) ($tags['addr:block_number'] ?? ''));
        $houseNumber = trim((string) ($tags['addr:housenumber'] ?? ''));

        if ($blockNumber !== '') {
            $street = $houseNumber === '' ? $blockNumber : $blockNumber.'-'.$houseNumber;
        } else {
            $street = trim(implode(' ', array_filter([
                trim((string) ($tags['addr:street'] ?? '')),
                $houseNumber,
            ], fn (string $part) => $part !== '')));
        }

        $place = implode('', $locality);

        if ($place !== '' && $street !== '') {
            return TaiwanAddress::tidy(str_contains($place, $street) ? $place : $place.$street);
        }

        if ($street !== '') {
            return TaiwanAddress::tidy($street);
        }

        return $place !== '' ? TaiwanAddress::tidy($place) : null;
    }
}
