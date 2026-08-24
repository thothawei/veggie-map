<?php

namespace Tests\Feature\Api;

use App\Models\ExternalApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_places_from_nominatim(): void
    {
        Http::fake([
            '*nominatim*' => Http::response([
                [
                    'display_name' => '台中一中街, 台中市, 台灣',
                    'lat' => '24.1477',
                    'lon' => '120.6842',
                ],
            ], 200),
        ]);

        $this->getJson('/api/v1/geocode?q='.urlencode('台中一中街'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    ['display_name' => '台中一中街, 台中市, 台灣', 'latitude' => 24.1477, 'longitude' => 120.6842],
                ],
            ]);

        $this->assertDatabaseHas('external_api_logs', [
            'provider' => 'nominatim',
            'success' => true,
        ]);
    }

    public function test_search_requires_query_param(): void
    {
        $this->getJson('/api/v1/geocode')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_search_returns_empty_when_nominatim_fails(): void
    {
        Http::fake([
            '*nominatim*' => Http::response([], 503),
        ]);

        $this->getJson('/api/v1/geocode?q=doesnotexist')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => []]);

        $this->assertDatabaseHas('external_api_logs', [
            'provider' => 'nominatim',
            'success' => false,
            'error_code' => 'HTTP_503',
        ]);
    }

    public function test_repeated_query_is_served_from_cache_without_calling_nominatim_again(): void
    {
        Http::fake([
            '*nominatim*' => Http::response([
                ['display_name' => '台北車站', 'lat' => '25.0478', 'lon' => '121.5170'],
            ], 200),
        ]);

        $this->getJson('/api/v1/geocode?q='.urlencode('台北車站'))->assertOk();
        $this->getJson('/api/v1/geocode?q='.urlencode('台北車站'))->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, ExternalApiLog::count());
    }
}
