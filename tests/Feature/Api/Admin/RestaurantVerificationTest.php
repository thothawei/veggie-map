<?php

namespace Tests\Feature\Api\Admin;

use App\Jobs\CalculateRestaurantScoreJob;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_admin_can_record_a_manual_verification_and_score_is_recalculated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                'verification_type' => 'admin_verified',
                'note' => '實地確認整間店都是素食',
            ])
            ->assertCreated()
            ->assertJsonPath('data.verification_type', 'admin_verified')
            ->assertJsonPath('data.score', config('vegetarian.verification_weights.admin_verified'))
            ->assertJsonPath('data.verified_by', $admin->id)
            ->assertJsonPath('data.metadata.note', '實地確認整間店都是素食')
            ->assertJsonPath('data.confidence_score', config('vegetarian.verification_weights.admin_verified'));

        $this->assertDatabaseHas('restaurant_verifications', [
            'restaurant_id' => $restaurant->id,
            'verification_type' => 'admin_verified',
            'verified_by' => $admin->id,
        ]);

        $this->assertSame(
            (int) config('vegetarian.verification_weights.admin_verified'),
            $restaurant->fresh()->confidenceScore->score,
        );
    }

    public function test_two_types_add_up_but_the_same_type_twice_does_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        foreach (['admin_verified', 'menu_verified', 'admin_verified'] as $type) {
            $this->withHeaders($this->headers($admin))
                ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                    'verification_type' => $type,
                ])
                ->assertCreated();
        }

        $expected = (int) config('vegetarian.verification_weights.admin_verified')
            + (int) config('vegetarian.verification_weights.menu_verified');

        $this->assertSame($expected, $restaurant->fresh()->confidenceScore->score);
    }

    public function test_types_outside_the_config_list_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        // external_source 由 OSM 同步依 venue kind 算分，不開放手動寫入。
        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                'verification_type' => 'external_source',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('restaurant_verifications', 0);
    }

    public function test_type_removed_from_config_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        config(['vegetarian.admin_verifiable_types' => [
            ['code' => 'admin_verified', 'label' => 'Admin 已查證'],
        ]]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                'verification_type' => 'menu_verified',
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_list_the_writable_verification_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->headers($admin))
            ->getJson('/api/v1/admin/verification-types')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'admin_verified')
            ->assertJsonPath('data.0.score', config('vegetarian.verification_weights.admin_verified'))
            ->assertJsonCount(count(config('vegetarian.admin_verifiable_types')), 'data');
    }

    public function test_non_admin_cannot_list_verification_types(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/admin/verification-types')
            ->assertStatus(403);
    }

    public function test_expires_at_must_be_in_the_future(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                'verification_type' => 'menu_verified',
                'expires_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    public function test_expired_verification_stops_counting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                'verification_type' => 'menu_verified',
                'expires_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertSame(
            (int) config('vegetarian.verification_weights.menu_verified'),
            $restaurant->fresh()->confidenceScore->score,
        );

        $this->travel(2)->days();
        CalculateRestaurantScoreJob::dispatchSync($restaurant->id);

        $this->assertSame(0, $restaurant->fresh()->confidenceScore->score);
    }

    public function test_non_admin_cannot_record_a_verification(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $restaurant = Restaurant::factory()->create();

        $this->withHeaders($this->headers($user))
            ->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
                'verification_type' => 'admin_verified',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('restaurant_verifications', 0);
    }

    public function test_guest_cannot_record_a_verification(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->postJson("/api/v1/admin/restaurants/{$restaurant->id}/verifications", [
            'verification_type' => 'admin_verified',
        ])->assertStatus(401);
    }
}
