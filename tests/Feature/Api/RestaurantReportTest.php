<?php

namespace Tests\Feature\Api;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_requires_authentication(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->postJson("/api/v1/restaurants/{$restaurant->id}/reports", ['type' => 'wrong_info'])
            ->assertStatus(401);
    }

    public function test_store_succeeds_for_authenticated_user(): void
    {
        // regression：Policy 命名要對上 RestaurantReportPolicy（而非 ReportPolicy），
        // 命名對不上時 Laravel 會默默回 403，見 progress.md Phase 5 記錄。
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken])
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reports", [
                'type' => 'wrong_info',
                'description' => 'phone number is wrong',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('restaurant_reports', [
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'type' => 'wrong_info',
        ]);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken])
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reports", ['type' => 'bogus'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_cannot_report_an_inactive_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create(['status' => 'pending']);
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken])
            ->postJson("/api/v1/restaurants/{$restaurant->id}/reports", ['type' => 'wrong_info'])
            ->assertStatus(404);
    }
}
