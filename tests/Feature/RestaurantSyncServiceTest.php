<?php

namespace Tests\Feature;

use App\Models\DietType;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantVerification;
use App\Services\External\BoundingBox;
use App\Services\External\RestaurantData;
use App\Services\External\RestaurantProviderInterface;
use App\Services\OpeningHoursService;
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
        return new RestaurantSyncService($provider, app(VerificationService::class), app(OpeningHoursService::class));
    }

    public function test_sync_parses_opening_hours_and_derives_the_timezone(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-hours',
                name: '有營業時間的店',
                latitude: 25.03,          // 台北 bbox 內
                longitude: 121.55,
                openingHours: 'Mo-Fr 11:00-14:00',
            ),
            new RestaurantData(
                sourceId: 'node-tokyo',
                name: '東京の店',
                latitude: 35.68,          // 東京 bbox 內
                longitude: 139.76,
                openingHours: '24/7',
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $taipei = Restaurant::where('source_id', 'node-hours')->firstOrFail();
        $this->assertSame('Asia/Taipei', $taipei->timezone);
        $this->assertSame('Mo-Fr 11:00-14:00', $taipei->opening_hours);
        $this->assertSame(5, $taipei->openingHours()->count());

        $tokyo = Restaurant::where('source_id', 'node-tokyo')->firstOrFail();
        $this->assertSame('Asia/Tokyo', $tokyo->timezone, '時區依座標落在哪個城市 bbox 決定');
        $this->assertSame(7, $tokyo->openingHours()->count());
    }

    public function test_resync_replaces_old_opening_hours_instead_of_accumulating(): void
    {
        $before = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-hours',
                name: '改時間的店',
                latitude: 25.03,
                longitude: 121.55,
                openingHours: 'Mo-Su 09:00-21:00',
            ),
        ]);
        $this->service($before)->sync(new BoundingBox(0, 0, 90, 180));

        $after = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-hours',
                name: '改時間的店',
                latitude: 25.03,
                longitude: 121.55,
                openingHours: 'Mo-Fr 09:00-21:00',
            ),
        ]);
        $this->service($after)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-hours')->firstOrFail();

        // 舊的週六／週日時段必須整批換掉，否則店家改成週末公休之後，open_now
        // 仍然會在週日把它算成營業中。
        $this->assertSame(5, $restaurant->openingHours()->count());
        $this->assertSame(0, $restaurant->openingHours()->where('day_of_week', 6)->count());
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

        $this->assertSame(0, $restaurant->menuItems()->count());
    }

    public function test_sync_replaces_osm_managed_diet_types_instead_of_accumulating(): void
    {
        DietType::factory()->create(['code' => 'vegetarian']);
        DietType::factory()->create(['code' => 'vegetarian_friendly']);

        $first = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-diet',
                name: '後來被改成友善店',
                latitude: 35.66,
                longitude: 139.70,
                dietCodes: ['vegetarian'],
            ),
        ]);
        $this->service($first)->sync(new BoundingBox(0, 0, 90, 180));

        $second = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-diet',
                name: '後來被改成友善店',
                latitude: 35.66,
                longitude: 139.70,
                dietCodes: ['vegetarian_friendly'],
            ),
        ]);
        $this->service($second)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-diet')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['vegetarian_friendly'],
            $restaurant->dietTypes->pluck('code')->all(),
        );
    }

    public function test_sync_keeps_manually_added_diet_types_that_osm_does_not_manage(): void
    {
        DietType::factory()->create(['code' => 'vegan']);
        DietType::factory()->create(['code' => 'vegetarian']);
        $ovoLacto = DietType::factory()->create(['code' => 'ovo_lacto']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-manual-diet',
                name: '手動加過蛋奶素',
                latitude: 25.03,
                longitude: 121.55,
                dietCodes: ['vegan'],
            ),
        ]);
        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-manual-diet')->firstOrFail();
        $restaurant->dietTypes()->syncWithoutDetaching($ovoLacto);

        $next = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-manual-diet',
                name: '手動加過蛋奶素',
                latitude: 25.03,
                longitude: 121.55,
                dietCodes: ['vegetarian'],
            ),
        ]);
        $this->service($next)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertEqualsCanonicalizing(
            ['vegetarian', 'ovo_lacto'],
            $restaurant->fresh()->dietTypes->pluck('code')->all(),
        );
    }

    public function test_friendly_venues_get_a_lower_external_source_score(): void
    {
        DietType::factory()->create(['code' => 'vegetarian']);
        DietType::factory()->create(['code' => 'vegetarian_friendly']);

        $exclusive = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-friendly-score',
                name: '先被標成素食店',
                latitude: 35.66,
                longitude: 139.70,
                dietCodes: ['vegetarian'],
            ),
        ]);
        $this->service($exclusive)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-friendly-score')->firstOrFail();
        $this->assertSame(
            (int) config('diet.confidence.external_source.exclusive'),
            $restaurant->verifications()->where('verification_type', 'external_source')->value('score'),
        );

        $friendly = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-friendly-score',
                name: '先被標成素食店',
                latitude: 35.66,
                longitude: 139.70,
                dietCodes: ['vegetarian_friendly'],
            ),
        ]);
        $this->service($friendly)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertSame(1, RestaurantVerification::where('verification_type', 'external_source')->count());
        $this->assertSame(
            (int) config('diet.confidence.external_source.friendly'),
            $restaurant->fresh()->verifications()->where('verification_type', 'external_source')->value('score'),
        );
        $this->assertSame(
            (int) config('diet.confidence.external_source.friendly'),
            $restaurant->fresh()->confidenceScore->score,
        );
    }

    public function test_duplicate_external_source_rows_are_collapsed_so_old_high_scores_cannot_win(): void
    {
        DietType::factory()->create(['code' => 'vegetarian_friendly']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-dup-score',
                name: '舊的十分還在',
                latitude: 35.66,
                longitude: 139.70,
                dietCodes: ['vegetarian_friendly'],
            ),
        ]);
        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-dup-score')->firstOrFail();
        $restaurant->verifications()->create([
            'verification_type' => 'external_source',
            'score' => 10,
            'verified_at' => now(),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertSame(1, $restaurant->fresh()->verifications()->where('verification_type', 'external_source')->count());
        $this->assertSame(
            (int) config('diet.confidence.external_source.friendly'),
            $restaurant->fresh()->confidenceScore->score,
        );
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

    public function test_slug_uses_pinyin_for_chinese_names(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(sourceId: 'node-3', name: '清心蔬食', latitude: 25.0, longitude: 121.5),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-3')->firstOrFail();
        $this->assertSame('qing-xin-shu-shi', $restaurant->slug);
    }

    public function test_duplicate_chinese_names_get_a_numeric_suffix(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(sourceId: 'a', name: '清心蔬食', latitude: 25.0, longitude: 121.5),
            new RestaurantData(sourceId: 'b', name: '清心蔬食', latitude: 24.1, longitude: 120.6),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $this->assertEqualsCanonicalizing(
            ['qing-xin-shu-shi', 'qing-xin-shu-shi-2'],
            Restaurant::whereIn('source_id', ['a', 'b'])->pluck('slug')->all(),
        );
    }

    public function test_slug_falls_back_to_source_seed_when_name_has_no_transliteration(): void
    {
        $provider = $this->fakeProvider([
            new RestaurantData(sourceId: 'node-3', name: '😊', latitude: 25.0, longitude: 121.5),
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

    public function test_sync_attaches_cuisine_codes_as_features(): void
    {
        Feature::factory()->create(['code' => 'japanese', 'label' => '日式料理']);
        Feature::factory()->create(['code' => 'takeout']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-c1',
                name: '日式素食',
                latitude: 25.03,
                longitude: 121.55,
                featureCodes: ['takeout'],
                cuisineCodes: ['japanese'],
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-c1')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['japanese', 'takeout'],
            $restaurant->features->pluck('code')->all(),
        );

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.features', ['takeout'])
            ->assertJsonPath('data.cuisines.0.code', 'japanese')
            ->assertJsonPath('data.cuisines.0.label', '日式料理');
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

    /**
     * 2026-08-26 產品決定加入無障礙。OSM 的 `wheelchair=limited` 語意是「部分
     * 無障礙（例如有斜坡但廁所不行）」——對需要的人來說仍然是有用的資訊，
     * 比完全查不到好。`no` 當然不收。
     */
    public function test_sync_maps_the_wheelchair_tag(): void
    {
        Feature::factory()->create(['code' => 'wheelchair', 'label' => '無障礙']);

        $provider = $this->fakeProvider([
            new RestaurantData(
                sourceId: 'node-wc-yes',
                name: '無障礙店',
                latitude: 25.03,
                longitude: 121.55,
                featureCodes: ['wheelchair'],
            ),
        ]);

        $this->service($provider)->sync(new BoundingBox(0, 0, 90, 180));

        $restaurant = Restaurant::where('source_id', 'node-wc-yes')->firstOrFail();
        $this->assertTrue($restaurant->features->pluck('code')->contains('wheelchair'));
    }
}
