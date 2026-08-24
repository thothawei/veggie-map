<?php

namespace Tests\Feature\Api;

use App\Models\DietType;
use App\Models\Restaurant;
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

    public function test_show_returns_restaurant_with_relations(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $restaurant->id)
            ->assertJsonPath('data.confidence_score', null)
            ->assertJsonStructure(['data' => ['diet_types', 'features', 'menu_items']]);
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
}
