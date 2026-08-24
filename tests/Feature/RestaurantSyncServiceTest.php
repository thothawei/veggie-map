<?php

namespace Tests\Feature;

use App\Models\DietType;
use App\Models\Restaurant;
use App\Services\External\BoundingBox;
use App\Services\External\RestaurantData;
use App\Services\External\RestaurantProviderInterface;
use App\Services\RestaurantSyncService;
use App\Services\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(array $items, string $source = 'osm'): RestaurantProviderInterface
    {
        return new class($items, $source) implements RestaurantProviderInterface
        {
            public function __construct(private array $items, private string $source)
            {
            }

            public function fetch(BoundingBox $bbox): array
            {
                return $this->items;
            }

            public function sourceName(): string
            {
                return $this->source;
            }
        };
    }

    private function service(RestaurantProviderInterface $provider): RestaurantSyncService
    {
        return new RestaurantSyncService($provider, app(VerificationService::class));
    }

    public function test_sync_creates_restaurant_with_diet_types_and_verification(): void
    {
        DietType::factory()->create(['code' => 'vegan']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-1',
                name: '測試蔬食餐廳',
                latitude: 25.03,
                longitude: 121.55,
                dietCodes: ['vegan'],
            ),
        ]);

        $stats = $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertSame(['created' => 1, 'updated' => 0, 'duplicates_flagged' => 0, 'skipped' => 0], $stats);

        $restaurant = Restaurant::where('source_id', 'node-1')->firstOrFail();
        $this->assertSame('osm', $restaurant->source);
        $this->assertSame('active', $restaurant->status);
        $this->assertTrue($restaurant->dietTypes->pluck('code')->contains('vegan'));

        $this->assertDatabaseHas('restaurant_verifications', [
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'external_source',
            'score' => config('vegetarian.verification_weights.external_source'),
        ]);

        $this->assertDatabaseHas('restaurant_confidence_scores', [
            'restaurant_id' => $restaurant->id,
        ]);
    }

    public function test_sync_is_idempotent_for_the_same_source_id(): void
    {
        $item = new RestaurantData(sourceId: 'node-2', name: '重複測試店', latitude: 25.0, longitude: 121.5);
        $provider = $this->fakeProvider([$item]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));
        $stats = $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, Restaurant::where('source_id', 'node-2')->count());
    }

    public function test_sync_flags_same_name_nearby_restaurants_as_possible_duplicates(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(sourceId: 'a', name: '同名店', latitude: 25.0330, longitude: 121.5645),
            new RestaurantData(sourceId: 'b', name: '同名店', latitude: 25.0331, longitude: 121.5646), // ~15m
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertSame(2, Restaurant::where('name', '同名店')->where('is_possible_duplicate', true)->count());
    }

    public function test_sync_does_not_flag_same_name_far_apart_restaurants(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(sourceId: 'a', name: '連鎖店', latitude: 25.0330, longitude: 121.5645),
            new RestaurantData(sourceId: 'b', name: '連鎖店', latitude: 24.1477, longitude: 120.6736), // 台中，遠得多
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertSame(0, Restaurant::where('name', '連鎖店')->where('is_possible_duplicate', true)->count());
    }

    public function test_slug_falls_back_to_source_seed_when_name_has_no_ascii_transliteration(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(sourceId: 'node-3', name: '純中文名稱店', latitude: 25.0, longitude: 121.5),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-3')->firstOrFail();
        $this->assertSame('osm-node-3', $restaurant->slug);
    }

    public function test_command_runs_with_mock_provider_against_the_bundled_fixture(): void
    {
        $this->artisan('restaurants:sync', ['--provider' => 'mock', '--bbox' => '21.5,119.5,25.5,122.5'])
            ->assertExitCode(0);

        $this->assertSame(5, Restaurant::where('source', 'osm')->count());
    }

    public function test_command_requires_bbox(): void
    {
        $this->artisan('restaurants:sync')->assertExitCode(1);
    }
}
