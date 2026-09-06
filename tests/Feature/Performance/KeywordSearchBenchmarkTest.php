<?php

namespace Tests\Feature\Performance;

use App\Repositories\RestaurantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 效能天花板（A9）。
 *
 * `KeywordSearch` 用 `LIKE '%…%'` 不是 MySQL FULLTEXT——理由是中文斷詞需要
 * ngram parser，資料量還小時上它換不到什麼，卻會讓權重不可控（見
 * KeywordSearch 類別註解）。這個判斷是對的，但不能只是嘴上說「資料量還小」，
 * 得有一條會在資料長大、真的變慢時**變紅**的線，不然「該回頭談索引了」
 * 這句話永遠沒有觸發的一天。
 *
 * 灌 10 倍資料（前幾輪的量測基準是 1159 家，這裡灌到約 12,000 家）跑一個
 * 8 變體的查詢（config('veggiemap.search.synonyms') 裡「珍珠奶茶」那組剛好
 * 8 個詞，不多不少踩滿 max_variants，不用另外湊）。
 */
class KeywordSearchBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_COUNT = 12_000;

    private const BATCH_SIZE = 1_000;

    public function test_eight_variant_keyword_search_stays_under_the_configured_ceiling(): void
    {
        $this->seedRestaurants(self::RESTAURANT_COUNT);

        $threshold = (int) config('veggiemap.search.benchmark_threshold_ms');
        $this->assertGreaterThan(0, $threshold, 'benchmark_threshold_ms 沒設好，門檻是 0 或負數這條測試沒有意義。');

        $repository = app(RestaurantRepository::class);

        // 每次查詢命中的 cache（見 RestaurantRepository::search 的 Cache::tags(['restaurants'])
        // ->remember）要清掉，不然量到的是「Redis 拿字串」的時間，不是查詢本身的時間——
        // 那樣不管索引好壞、資料量多寡，這條測試永遠是綠的。
        Cache::tags(['restaurants'])->flush();

        $startedAt = microtime(true);

        $paginator = $repository->search(['keyword' => '珍珠奶茶', 'per_page' => 20]);
        $paginator->items();

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertLessThan(
            $threshold,
            $elapsedMs,
            sprintf(
                '%d 家資料下，8 變體關鍵字查詢花了 %.1fms，超過門檻 %dms——'.
                '該回頭談索引／FULLTEXT／外部搜尋引擎了，不是調高這個數字打發過去。',
                self::RESTAURANT_COUNT,
                $elapsedMs,
                $threshold,
            ),
        );
    }

    /**
     * 反向驗證用：把 keyword 拿掉（0 變體，純瀏覽）理論上比 8 變體快得多。
     * 這條不是守正式驗收條件，是拿來確認上面那條測試真的在測「多變體的成本」，
     * 不是測「資料庫連得上」這種永遠會過的東西。
     */
    public function test_plain_browse_without_keyword_is_faster_than_the_eight_variant_search(): void
    {
        $this->seedRestaurants(self::RESTAURANT_COUNT);

        $repository = app(RestaurantRepository::class);

        Cache::tags(['restaurants'])->flush();
        $withoutKeyword = microtime(true);
        $repository->search(['per_page' => 20])->items();
        $withoutKeywordMs = (microtime(true) - $withoutKeyword) * 1000;

        Cache::tags(['restaurants'])->flush();
        $withKeyword = microtime(true);
        $repository->search(['keyword' => '珍珠奶茶', 'per_page' => 20])->items();
        $withKeywordMs = (microtime(true) - $withKeyword) * 1000;

        $this->assertLessThan(
            $withKeywordMs,
            $withoutKeywordMs,
            '沒有關鍵字（0 個 LIKE 群組）理應比 8 變體查詢快，如果不是，代表上面那條'.
            '基準測試量到的可能不是關鍵字比對的成本。',
        );
    }

    /**
     * 用 query builder 直接批次寫入，繞過 Eloquent factory 逐筆 save 的開銷——
     * 12,000 筆用 factory()->create() 跑測試會慢到沒人想執行這條測試。
     * 這裡只需要「量夠大、欄位齊全到查詢跑得動」，不需要每筆都經過 model event。
     */
    private function seedRestaurants(int $count): void
    {
        $now = now();
        $batch = [];

        for ($i = 1; $i <= $count; $i++) {
            // 每 37 筆放一家名字含「珍珠奶茶」的店，確保查詢不是對著一張空表跑，
            // 但也不用整張表都命中——比對成本主要來自掃描與 whereHas，不是回傳筆數。
            $name = $i % 37 === 0
                ? "珍珠奶茶專賣店 {$i}"
                : "測試蔬食餐廳 {$i}";

            $lat = 24.10 + (($i % 500) / 500) * 0.3;
            $lng = 120.55 + (($i % 500) / 500) * 0.3;

            $batch[] = [
                'name' => $name,
                'slug' => "benchmark-restaurant-{$i}",
                'description' => null,
                'address' => "測試路{$i}號",
                'city' => '台中市',
                'district' => $i % 2 === 0 ? '西區' : '北屯區',
                'latitude' => $lat,
                'longitude' => $lng,
                'location' => DB::raw("ST_SRID(POINT({$lng}, {$lat}), 4326)"),
                'phone' => null,
                'website' => null,
                'price_level' => ($i % 4) + 1,
                'rating' => 0,
                'rating_count' => 0,
                'source' => 'manual',
                'source_id' => null,
                'status' => 'active',
                'is_possible_duplicate' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                DB::table('restaurants')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('restaurants')->insert($batch);
        }
    }
}
