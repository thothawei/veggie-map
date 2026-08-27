<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Restaurant;
use App\Models\RestaurantClosureSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 疑似歇業的 Admin 審核。偵測端只寫訊號，這裡是它們唯一的出口。
 */
class ClosureSignalTest extends TestCase
{
    use RefreshDatabase;

    private function signal(array $attributes = []): RestaurantClosureSignal
    {
        $restaurant = Restaurant::factory()->create(['status' => 'active']);

        return RestaurantClosureSignal::create($attributes + [
            'restaurant_id' => $restaurant->id,
            'signal' => 'osm_node_missing',
            'metadata' => ['source_id' => '123'],
            'detected_at' => now(),
        ]);
    }

    public function test_pending_signals_are_listed_with_a_google_maps_link(): void
    {
        $signal = $this->signal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/v1/admin/closures')->assertOk();

        $response->assertJsonPath('data.0.id', $signal->id)
            ->assertJsonPath('data.0.signal', 'osm_node_missing');
        // Admin 判斷「這家店還在不在」最快的方式就是去 Google 地圖看一眼，
        // 連結直接給，不要逼他複製座標。
        $this->assertStringContainsString(
            'google.com/maps',
            $response->json('data.0.restaurant.google_maps_url'),
        );
    }

    public function test_resolved_signals_do_not_show_up_again(): void
    {
        $this->signal(['resolution' => 'dismissed', 'reviewed_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->getJson('/api/v1/admin/closures')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_confirming_deactivates_the_restaurant(): void
    {
        $signal = $this->signal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/v1/admin/closures/{$signal->id}", ['resolution' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.restaurant_status', 'inactive');

        // 下架而不是刪除，跟回報核准、重複審核一致。
        $this->assertSame('inactive', $signal->restaurant->fresh()->status);
        $this->assertDatabaseCount('restaurants', 1);
    }

    public function test_dismissing_leaves_the_restaurant_alone(): void
    {
        $signal = $this->signal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/v1/admin/closures/{$signal->id}", ['resolution' => 'dismissed'])
            ->assertOk();

        $this->assertSame('active', $signal->restaurant->fresh()->status);
        $this->assertSame('dismissed', $signal->fresh()->resolution);
    }

    public function test_an_invalid_resolution_is_rejected(): void
    {
        $signal = $this->signal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        // 沒有「再看看」這種選項：訊號要嘛成立要嘛不成立。
        $this->postJson("/api/v1/admin/closures/{$signal->id}", ['resolution' => 'maybe'])
            ->assertStatus(422);

        $this->assertSame('active', $signal->restaurant->fresh()->status);
    }

    public function test_a_normal_user_cannot_review_or_even_list(): void
    {
        $signal = $this->signal();
        $this->actingAs(User::factory()->create(['role' => 'user']));

        $this->getJson('/api/v1/admin/closures')->assertStatus(403);
        $this->postJson("/api/v1/admin/closures/{$signal->id}", ['resolution' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('active', $signal->restaurant->fresh()->status);
    }

    public function test_guests_are_rejected(): void
    {
        $signal = $this->signal();

        $this->getJson('/api/v1/admin/closures')->assertStatus(401);
        $this->postJson("/api/v1/admin/closures/{$signal->id}", ['resolution' => 'confirmed'])
            ->assertStatus(401);
    }
}
