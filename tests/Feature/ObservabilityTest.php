<?php

namespace Tests\Feature;

use App\Support\CacheStatsRecorder;
use App\Support\QueryPerformanceLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * API response time（總 Prompt 第三十五節）。在這之前只有外部 API 呼叫有
 * `response_time_ms`，一般端點完全沒有量測——`docs/observability.md` 也誠實
 * 記著這個缺口。
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_response_carries_the_duration_header(): void
    {
        $response = $this->getJson('/api/v1/cities');

        $response->assertOk();
        $this->assertTrue($response->headers->has('X-Response-Time-Ms'));
        $this->assertIsNumeric($response->headers->get('X-Response-Time-Ms'));
    }

    public function test_fast_requests_are_not_logged(): void
    {
        // 每一筆都寫 log 等於自製一個沒人看的 APM。
        config(['veggiemap.observability.slow_request_ms' => 10000]);

        Log::shouldReceive('warning')->never();

        $this->getJson('/api/v1/cities')->assertOk();
    }

    public function test_slow_requests_are_logged_with_the_route_template_not_the_url(): void
    {
        // 門檻設 0 讓任何請求都算慢，不必真的拖慢一個端點。
        config(['veggiemap.observability.slow_request_ms' => 0]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                // 逐筆 id 會變成幾千個獨一無二的字串，聚合不起來；而且 query string
                // 帶著使用者搜尋的關鍵字與座標，是個人資料，不該進 log。
                return $message === 'Slow API request'
                    && $context['route'] === 'api/v1/cities'
                    && $context['status'] === 200
                    && ! array_key_exists('query', $context);
            });

        $this->getJson('/api/v1/cities')->assertOk();
    }

    public function test_slow_queries_are_logged_without_bindings(): void
    {
        config(['veggiemap.observability.slow_query_ms' => 200]);

        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$logged) {
            $logged[] = [$message, $context];
        });

        // 直接餵一個假的 QueryExecuted，不必想辦法讓真的查詢變慢——那會讓測試
        // 又慢又不穩定。
        QueryPerformanceLogger::handle(new QueryExecuted(
            'select * from restaurants where name like ?',
            ['%機密關鍵字%'],
            250.0,
            DB::connection(),
        ));

        $this->assertCount(1, $logged);
        [$message, $context] = $logged[0];

        $this->assertSame('Slow database query', $message);
        $this->assertSame(250.0, $context['duration_ms']);
        // 搜尋條件裡有使用者打的關鍵字與座標，是個人資料，不該進 log。
        $this->assertArrayNotHasKey('bindings', $context);
        $this->assertStringNotContainsString('機密關鍵字', json_encode($context, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_fast_queries_are_not_logged(): void
    {
        config(['veggiemap.observability.slow_query_ms' => 200]);

        Log::shouldReceive('warning')->never();

        QueryPerformanceLogger::handle(new QueryExecuted('select 1', [], 5.0, DB::connection()));
    }

    public function test_cache_hits_and_misses_are_counted_per_key_family(): void
    {
        Cache::flush();

        // 同一組條件查三次：第一次 miss，後兩次 hit。
        foreach (range(1, 3) as $ignored) {
            $this->getJson('/api/v1/restaurants?per_page=5')->assertOk();
        }

        $stats = CacheStatsRecorder::snapshot();

        $this->assertSame(1, $stats['restaurants:search']['miss']);
        $this->assertGreaterThanOrEqual(2, $stats['restaurants:search']['hit']);
    }

    /**
     * 沒有樣本時 ratio 是 null 而不是 0——「這段時間沒人查」跟「命中率 0%」是兩件事，
     * 印成 0% 會讓人以為快取壞了。
     */
    public function test_ratio_is_null_when_there_are_no_samples(): void
    {
        Cache::flush();

        $this->assertNull(CacheStatsRecorder::snapshot()['geocode']['ratio']);
    }

    /**
     * `restaurants:search:{md5}` 的 hash 是查詢條件算出來的，逐個記等於記下每一次
     * 搜尋，還會產生幾萬個 key。只記家族名稱。
     */
    public function test_counters_are_keyed_by_family_not_by_the_full_cache_key(): void
    {
        Cache::flush();

        $this->getJson('/api/v1/restaurants?per_page=5&keyword=祕密關鍵字')->assertOk();

        $stats = CacheStatsRecorder::snapshot();

        $this->assertSame(1, $stats['restaurants:search']['miss']);
        $this->assertArrayNotHasKey('restaurants:search:'.md5('x'), $stats);
    }
}
