<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Feature;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Support\DietCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestaurantTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_active_restaurants(): void
    {
        Restaurant::factory()->create(['status' => 'active']);
        Restaurant::factory()->create(['status' => 'pending']);

        $response = $this->getJson('/api/v1/restaurants');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_radius_search_orders_by_distance_and_excludes_far_restaurants(): void
    {
        // 台北 101 附近，約 200m
        $near = Restaurant::factory()->create([
            'latitude' => 25.0332,
            'longitude' => 121.5645,
            'location' => DB::raw('ST_SRID(POINT(121.5645, 25.0332), 4326)'),
        ]);

        // 台中，離台北 101 上百公里，radius=5 應該濾掉
        Restaurant::factory()->create([
            'latitude' => 24.1477,
            'longitude' => 120.6736,
            'location' => DB::raw('ST_SRID(POINT(120.6736, 24.1477), 4326)'),
        ]);

        $response = $this->getJson('/api/v1/restaurants?latitude=25.0330&longitude=121.5643&radius=5');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($near->id, $response->json('data.0.id'));
        // JSON 編碼會把整數值的浮點數（例如 30.0）序列化成 30，json_decode 讀回來是
        // PHP int，所以驗證「有數值」用 assertIsNumeric，不是 assertIsFloat。
        $this->assertIsNumeric($response->json('data.0.distance_meters'));
    }

    public function test_sort_distance_without_coordinates_is_rejected(): void
    {
        $this->getJson('/api/v1/restaurants?sort=distance')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_cursor_pagination_walks_through_all_pages_without_duplicates(): void
    {
        Restaurant::factory()->count(5)->create();

        $page1 = $this->getJson('/api/v1/restaurants?per_page=2')->json();
        $this->assertCount(2, $page1['data']);
        $this->assertNotNull($page1['meta']['next_cursor']);

        $page2 = $this->getJson('/api/v1/restaurants?per_page=2&cursor='.$page1['meta']['next_cursor'])->json();
        $this->assertCount(2, $page2['data']);

        $ids = array_merge(array_column($page1['data'], 'id'), array_column($page2['data'], 'id'));
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_diet_filter_only_returns_restaurants_with_that_diet_type(): void
    {
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        $vegetarian = DietType::factory()->create(['code' => 'vegetarian']);

        $veganRestaurant = Restaurant::factory()->create();
        $veganRestaurant->dietTypes()->attach($vegan);

        $vegetarianRestaurant = Restaurant::factory()->create();
        $vegetarianRestaurant->dietTypes()->attach($vegetarian);

        $response = $this->getJson('/api/v1/restaurants?diet=vegan');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($veganRestaurant->id, $response->json('data.0.id'));
    }

    public function test_venue_scope_filters_by_diet_kind_from_config(): void
    {
        $exclusive = DietType::factory()->create(['code' => 'vegan']);
        $friendly = DietType::factory()->create(['code' => 'vegetarian_friendly']);

        $pure = Restaurant::factory()->create(['name' => '十方齋']);
        $pure->dietTypes()->attach($exclusive);

        $mixed = Restaurant::factory()->create(['name' => 'CoCo']);
        $mixed->dietTypes()->attach($friendly);

        $omitted = $this->getJson('/api/v1/restaurants')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['十方齋', 'CoCo'],
            array_column($omitted->json('data'), 'name'),
        );

        $onlyExclusive = $this->getJson('/api/v1/restaurants?venue_scope=exclusive')->assertOk();
        $this->assertSame(['十方齋'], array_column($onlyExclusive->json('data'), 'name'));
        $this->assertSame('exclusive', $onlyExclusive->json('data.0.venue_kind'));
        $this->assertSame(config('diet.copy.exclusive.badge'), $onlyExclusive->json('data.0.venue_badge'));

        $onlyFriendly = $this->getJson('/api/v1/restaurants?venue_scope=friendly')->assertOk();
        $this->assertSame(['CoCo'], array_column($onlyFriendly->json('data'), 'name'));
        $this->assertSame('friendly', $onlyFriendly->json('data.0.venue_kind'));
        $this->assertSame(config('diet.copy.friendly.badge'), $onlyFriendly->json('data.0.venue_badge'));

        $all = $this->getJson('/api/v1/restaurants?venue_scope=all')->assertOk();
        $this->assertEqualsCanonicalizing(['十方齋', 'CoCo'], array_column($all->json('data'), 'name'));
    }

    public function test_unknown_venue_scope_is_rejected(): void
    {
        $this->getJson('/api/v1/restaurants?venue_scope=maybe')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_takeout_filter_returns_restaurants_with_that_feature(): void
    {
        $takeout = Feature::factory()->create(['code' => 'takeout']);
        Feature::factory()->create(['code' => 'wifi']);

        $withTakeout = Restaurant::factory()->create();
        $withTakeout->features()->attach($takeout);

        Restaurant::factory()->create();

        $response = $this->getJson('/api/v1/restaurants?takeout=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($withTakeout->id, $response->json('data.0.id'));
    }

    public function test_multiple_feature_filters_are_anded(): void
    {
        $takeout = Feature::factory()->create(['code' => 'takeout']);
        $wifi = Feature::factory()->create(['code' => 'wifi']);

        $both = Restaurant::factory()->create();
        $both->features()->attach([$takeout->id, $wifi->id]);

        $onlyTakeout = Restaurant::factory()->create();
        $onlyTakeout->features()->attach($takeout);

        $response = $this->getJson('/api/v1/restaurants?takeout=1&wifi=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($both->id, $response->json('data.0.id'));
    }

    public function test_boolean_true_string_is_accepted_for_feature_filters(): void
    {
        // axios 預設會把布林序列化成 "true"，Laravel boolean 規則原本不吃。
        $takeout = Feature::factory()->create(['code' => 'takeout']);
        $restaurant = Restaurant::factory()->create();
        $restaurant->features()->attach($takeout);

        $this->getJson('/api/v1/restaurants?takeout=true')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_restaurant_with_relations(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $restaurant->id)
            ->assertJsonPath('data.confidence_score', null)
            ->assertJsonStructure(['data' => ['diet_types', 'venue_kind', 'venue_badge', 'venue_summary', 'cuisines', 'features', 'menu_items']]);
    }

    public function test_show_includes_menu_empty_message_when_there_are_no_items(): void
    {
        $restaurant = Restaurant::factory()->create(['source' => 'osm']);
        $friendly = DietType::factory()->create(['code' => 'vegetarian_friendly']);
        $restaurant->dietTypes()->attach($friendly);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.menu_items', [])
            ->assertJsonPath('data.menu_empty_message', DietCatalog::menuEmptyMessage('friendly', 'osm'))
            ->assertJsonPath('data.venue_kind', 'friendly');
    }

    public function test_show_omits_empty_message_and_labels_items_from_config_when_menu_exists(): void
    {
        $restaurant = Restaurant::factory()->create();
        MenuItem::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => '白飯',
            'diet_type' => 'vegan',
        ]);

        $response = $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.menu_items.0.name', '白飯')
            ->assertJsonPath('data.menu_items.0.diet_type', 'vegan')
            ->assertJsonPath('data.menu_items.0.diet_label', DietCatalog::menuItemDietLabel('vegan'));

        $this->assertArrayNotHasKey('menu_empty_message', $response->json('data'));
    }

    public function test_show_returns_404_for_missing_restaurant(): void
    {
        $this->getJson('/api/v1/restaurants/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_show_returns_404_for_inactive_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create(['status' => 'pending']);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")->assertStatus(404);
    }

    public function test_recommended_requires_coordinates(): void
    {
        $this->getJson('/api/v1/restaurants/recommended')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_recommended_returns_ranked_restaurants_with_scores(): void
    {
        Restaurant::factory()->count(3)->create([
            'latitude' => 25.0332,
            'longitude' => 121.5645,
            'location' => DB::raw('ST_SRID(POINT(121.5645, 25.0332), 4326)'),
        ]);

        $response = $this->getJson('/api/v1/restaurants/recommended?latitude=25.0332&longitude=121.5645&radius=5&limit=2');

        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertNotNull($response->json('data.0.recommendation_score'));
        $this->assertGreaterThanOrEqual(
            $response->json('data.1.recommendation_score'),
            $response->json('data.0.recommendation_score'),
        );
    }

    public function test_recommended_respects_feature_filters(): void
    {
        $takeout = Feature::factory()->create(['code' => 'takeout']);

        $withTakeout = Restaurant::factory()->create([
            'latitude' => 25.0332,
            'longitude' => 121.5645,
            'location' => DB::raw('ST_SRID(POINT(121.5645, 25.0332), 4326)'),
        ]);
        $withTakeout->features()->attach($takeout);

        Restaurant::factory()->create([
            'latitude' => 25.0332,
            'longitude' => 121.5645,
            'location' => DB::raw('ST_SRID(POINT(121.5645, 25.0332), 4326)'),
        ]);

        $response = $this->getJson('/api/v1/restaurants/recommended?latitude=25.0332&longitude=121.5645&radius=5&takeout=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($withTakeout->id, $response->json('data.0.id'));
    }

    public function test_recommended_accepts_bbox_beyond_radius_cap(): void
    {
        Restaurant::factory()->create([
            'latitude' => 24.1477,
            'longitude' => 120.6736,
            'location' => DB::raw('ST_SRID(POINT(120.6736, 24.1477), 4326)'),
        ]);

        $this->getJson('/api/v1/restaurants/recommended?latitude=24.1477&longitude=120.6736&bbox=23.9500,120.4300,24.4500,121.4700')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_recommended_radius_over_50_is_rejected(): void
    {
        $this->getJson('/api/v1/restaurants/recommended?latitude=24.1477&longitude=120.6736&radius=51')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    /**
     * 總 Prompt 第三十二節：大型列表不要 SELECT *。這條同時守住「該省的省掉」與
     * 「省掉的欄位不能變成 null 說謊」。
     */
    public function test_list_omits_heavy_columns_instead_of_returning_them_as_null(): void
    {
        Restaurant::factory()->create(['description' => '這是一段很長的描述']);

        $listed = $this->getJson('/api/v1/restaurants')->json('data.0');

        $this->assertArrayNotHasKey('description', $listed, 'description 沒撈就不該出現在回應裡');
        $this->assertArrayNotHasKey('opening_hours_raw', $listed);
        $this->assertArrayHasKey('name', $listed);
        $this->assertArrayHasKey('latitude', $listed);
    }

    public function test_detail_still_returns_the_columns_the_list_skips(): void
    {
        $restaurant = Restaurant::factory()->create(['description' => '這是一段很長的描述']);

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.description', '這是一段很長的描述');
    }

    /**
     * `?diet=vegan,ovo_lacto`：多個飲食類型之間是 **OR**。素食者常常「全素或蛋奶素
     * 都可以」，AND 會把結果篩成 0——一家店不可能同時被標成全素又標成蛋奶素。
     */
    public function test_multiple_diet_codes_are_combined_with_or(): void
    {
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        $ovoLacto = DietType::factory()->create(['code' => 'ovo_lacto']);

        $veganShop = Restaurant::factory()->create(['name' => '全素店']);
        $veganShop->dietTypes()->attach($vegan->id);

        $ovoLactoShop = Restaurant::factory()->create(['name' => '蛋奶素店']);
        $ovoLactoShop->dietTypes()->attach($ovoLacto->id);

        Restaurant::factory()->create(['name' => '沒有標示的店']);

        $ids = array_column(
            $this->getJson('/api/v1/restaurants?diet=vegan,ovo_lacto&venue_scope=all')->json('data'),
            'id',
        );

        sort($ids);
        $expected = [$veganShop->id, $ovoLactoShop->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_single_diet_code_still_works(): void
    {
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        $veganShop = Restaurant::factory()->create();
        $veganShop->dietTypes()->attach($vegan->id);
        Restaurant::factory()->create();

        $this->getJson('/api/v1/restaurants?diet=vegan&venue_scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unknown_diet_code_in_the_list_is_rejected(): void
    {
        DietType::factory()->create(['code' => 'vegan']);

        $this->getJson('/api/v1/restaurants?diet=vegan,not_a_diet')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
