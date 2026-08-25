<?php

namespace Tests\Feature\External;

use App\Models\ExternalApiLog;
use App\Services\External\BoundingBox;
use App\Services\External\CircuitBreaker;
use App\Services\External\GeocodingUnavailableException;
use App\Services\External\NominatimGeocodingProvider;
use App\Services\External\OsmRestaurantProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 斷路器（總 Prompt 第二十節）。timeout／retry 已經有了，這裡守的是「連續失敗後
 * 不要再空等」——排程一次跑五個 bbox，五個獨立程序各自 retry 三次是十五次注定
 * 失敗的請求。
 */
class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.circuit_breaker.failure_threshold' => 2]);
    }

    public function test_state_is_shared_across_instances_because_it_lives_in_cache(): void
    {
        // 存在物件裡的計數器在「五個獨立 artisan 程序」的場景等於沒有，
        // 所以這條測試用兩個不同的實例。
        CircuitBreaker::for('overpass')->recordFailure();
        CircuitBreaker::for('overpass')->recordFailure();

        $this->assertFalse(CircuitBreaker::for('overpass')->available());
    }

    public function test_success_resets_the_failure_count(): void
    {
        $breaker = CircuitBreaker::for('overpass');

        $breaker->recordFailure();
        $breaker->recordSuccess();
        $breaker->recordFailure();

        $this->assertTrue($breaker->available(), '中間成功過就不算連續失敗');
    }

    public function test_overpass_stops_calling_after_the_threshold(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $provider = new OsmRestaurantProvider('only');
        $bbox = new BoundingBox(24.0, 120.0, 24.1, 120.1);

        $provider->fetch($bbox);
        $provider->fetch($bbox);

        Http::fake(['*' => Http::response('', 503)]);
        $provider->fetch($bbox);

        // 第三次應該完全沒有送出請求，只留下一筆 CIRCUIT_OPEN 的 log。
        Http::assertNothingSent();
        $this->assertSame(1, ExternalApiLog::where('error_code', 'CIRCUIT_OPEN')->count());
    }

    public function test_geocoding_fails_fast_while_the_circuit_is_open(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        $provider = new NominatimGeocodingProvider;

        foreach (range(1, 2) as $ignored) {
            try {
                $provider->search('台中');
            } catch (GeocodingUnavailableException) {
                // 預期會丟，這裡只是把失敗次數累積到門檻。
            }
        }

        Http::fake(['*' => Http::response('', 503)]);

        $this->expectException(GeocodingUnavailableException::class);

        try {
            $provider->search('台北');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_geocode_endpoint_returns_an_empty_list_not_a_500_when_open(): void
    {
        // 開路不該讓使用者看到伺服器錯誤——既有的 fallback 行為必須維持。
        CircuitBreaker::for('nominatim')->recordFailure();
        CircuitBreaker::for('nominatim')->recordFailure();

        $this->getJson('/api/v1/geocode?q=台中')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
