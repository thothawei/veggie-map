<?php

namespace Tests\Feature\Api\Admin;

use App\Models\DietType;
use App\Models\MenuItem;
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

    public function test_approving_not_vegetarian_on_exclusive_restaurant_demotes_to_friendly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        $veganFriendly = DietType::factory()->create(['code' => 'vegan_friendly']);
        $restaurant->dietTypes()->attach($vegan);

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'not_vegetarian',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['vegan_friendly'],
            $restaurant->fresh()->dietTypes()->pluck('code')->all(),
        );
        $this->assertDatabaseHas('diet_types', ['id' => $veganFriendly->id]);
    }

    public function test_approving_not_vegetarian_on_friendly_restaurant_keeps_friendly_codes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        $friendly = DietType::factory()->create(['code' => 'vegetarian_friendly']);
        $restaurant->dietTypes()->attach($friendly);

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'not_vegetarian',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['vegetarian_friendly'],
            $restaurant->fresh()->dietTypes()->pluck('code')->all(),
        );
        $this->assertSame('active', $restaurant->fresh()->status);
    }

    public function test_approving_menu_changed_clears_menu_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        MenuItem::factory()->count(3)->create(['restaurant_id' => $restaurant->id]);

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'menu_changed',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertSame(0, $restaurant->menuItems()->count());
    }

    /**
     * 一次超過一批（chunk 預設 1000 筆）：offset 分頁一邊刪一邊翻頁會跳過資料，
     * 這個數量才擋得住那個寫法。
     */
    public function test_approving_menu_changed_clears_menus_larger_than_one_chunk(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        MenuItem::factory()->count(1005)->create(['restaurant_id' => $restaurant->id]);

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'menu_changed',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertSame(0, $restaurant->menuItems()->count());
    }

    public function test_approving_closed_does_not_change_restaurant_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create(['status' => 'active']);
        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'closed',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertSame('active', $restaurant->fresh()->status);
    }

    public function test_rejecting_not_vegetarian_does_not_demote_the_restaurant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        DietType::factory()->create(['code' => 'vegan_friendly']);
        $restaurant->dietTypes()->attach($vegan);

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'not_vegetarian',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/reject")
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['vegan'],
            $restaurant->fresh()->dietTypes()->pluck('code')->all(),
        );
    }
}
