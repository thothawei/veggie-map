<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Restaurant;
use App\Models\RestaurantReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantReportTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_index_requires_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/admin/reports')
            ->assertStatus(403);
    }

    public function test_index_lists_pending_reports_by_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        RestaurantReport::factory()->create(['restaurant_id' => $restaurant->id, 'status' => 'pending']);
        RestaurantReport::factory()->create(['restaurant_id' => $restaurant->id, 'status' => 'approved']);

        $this->withHeaders($this->headers($admin))
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_approve_a_pending_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $report = RestaurantReport::factory()->create(['status' => 'pending']);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $admin->id);

        $this->assertDatabaseHas('restaurant_reports', [
            'id' => $report->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_admin_can_reject_a_pending_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $report = RestaurantReport::factory()->create(['status' => 'pending']);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_reviewing_an_already_reviewed_report_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $report = RestaurantReport::factory()->create(['status' => 'approved']);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_approve_a_report(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $report = RestaurantReport::factory()->create(['status' => 'pending']);

        $this->withHeaders($this->headers($user))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertStatus(403);

        $this->assertDatabaseHas('restaurant_reports', ['id' => $report->id, 'status' => 'pending']);
    }
}
