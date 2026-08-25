<?php

namespace Tests\Feature;

use App\Models\DietType;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantVerification;
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
            public function __construct(private array $items, private string $source) {}

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
        $this->assertSame(1, RestaurantVerification::where('verification_type', 'external_source')->count());
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

    public function test_sync_attaches_features_from_provider(): void
    {
        Feature::factory()->create(['code' => 'takeout']);
        Feature::factory()->create(['code' => 'wifi']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-f1',
                name: '有外帶有 wifi',
                latitude: 25.03,
                longitude: 121.55,
                featureCodes: ['takeout', 'wifi'],
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-f1')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['takeout', 'wifi'],
            $restaurant->features->pluck('code')->all(),
        );
    }

    public function test_sync_ignores_feature_codes_that_do_not_exist(): void
    {
        // 對不上任何已知 code 的一律丟掉，不硬塞——跟 diet types 同一套規則。
        Feature::factory()->create(['code' => 'takeout']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-f2',
                name: '有一個未知特色',
                latitude: 25.03,
                longitude: 121.55,
                featureCodes: ['takeout', 'teleportation'],
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-f2')->firstOrFail();

        $this->assertSame(['takeout'], $restaurant->features->pluck('code')->all());
    }

    public function test_sync_does_not_wipe_features_added_by_hand(): void
    {
        // 每天自動同步不該把 Admin 或使用者手動加上的特色洗掉；OSM 只負責補充它知道的部分。
        $takeout = Feature::factory()->create(['code' => 'takeout']);
        $parking = Feature::factory()->create(['code' => 'parking']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-f3',
                name: '手動加過停車',
                latitude: 25.03,
                longitude: 121.55,
                featureCodes: ['takeout'],
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-f3')->firstOrFail();
        $restaurant->features()->syncWithoutDetaching($parking);

        // 第二次同步（模擬隔天排程），OSM 依然只回報 takeout。
        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertEqualsCanonicalizing(
            ['takeout', 'parking'],
            $restaurant->fresh()->features->pluck('code')->all(),
        );
        $this->assertSame($takeout->id, $restaurant->fresh()->features->firstWhere('code', 'takeout')->id);
    }

    public function test_sync_without_feature_codes_leaves_the_restaurant_untouched(): void
    {
        Feature::factory()->create(['code' => 'takeout']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-f4',
                name: '沒有任何特色',
                latitude: 25.03,
                longitude: 121.55,
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertCount(0, Restaurant::where('source_id', 'node-f4')->firstOrFail()->features);
    }

    public function test_sync_invalidates_detail_cache_when_only_features_change(): void
    {
        // pivot 寫入不會觸發 Restaurant saved。同一筆餐廳重跑同步時若欄位沒變，
        // observer 也不會清快取——不在 sync 裡顯式 invalidate 的話，詳情會繼續吐舊的空特色。
        Feature::factory()->create(['code' => 'takeout']);

        $first = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-cache',
                name: '後來才有外帶',
                latitude: 25.03,
                longitude: 121.55,
            ),
        ]);
        $this->service($first)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-cache')->firstOrFail();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.features', []);

        $second = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-cache',
                name: '後來才有外帶',
                latitude: 25.03,
                longitude: 121.55,
                featureCodes: ['takeout'],
            ),
        ]);
        $this->service($second)->sync(new BoundingBox(0, 0, 90, 180));

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.features', ['takeout']);
    }

    public function test_unknown_provider_binding_throws_instead_of_silently_using_mock(): void
    {
        config(['services.restaurant_provider' => 'overpass']);
        $this->app->forgetInstance(RestaurantProviderInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overpass');

        app(RestaurantProviderInterface::class);
    }
}
