<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
