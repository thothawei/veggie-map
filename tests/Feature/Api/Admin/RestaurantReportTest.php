<?php

namespace Tests\Feature\Api\Admin;

use App\Models\DietType;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantReport;
use App\Models\RestaurantVerification;
use App\Models\User;
use App\Services\VerificationService;
use App\Support\DietCatalog;
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

    /**
     * 2026-08-26 產品決定：核准「已歇業」＝自動下架。
     *
     * 在這之前核准 `closed` 什麼都不做，歇業的店會一直留在地圖上——那正是使用者
     * 回報要解決的問題。核准本身就是人工判斷過了。
     */
    public function test_approving_closed_deactivates_the_restaurant(): void
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

        $this->assertSame('inactive', $restaurant->fresh()->status);

        // 下架不是刪除：判斷錯了救得回來。
        $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id]);

        // 而且地圖與列表立刻就看不到它——detail cache 的 key 是 id，
        // 不清的話會繼續吐 600 秒。
        $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/restaurants/{$restaurant->id}")->assertNotFound();
    }

    public function test_rejecting_closed_leaves_the_restaurant_alone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create(['status' => 'active']);
        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'closed',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/reject")
            ->assertOk();

        $this->assertSame('active', $restaurant->fresh()->status);
    }

    public function test_approving_a_report_records_a_user_report_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'wrong_info',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $verification = RestaurantVerification::where('restaurant_id', $restaurant->id)
            ->where('verification_type', 'user_report')
            ->sole();

        $this->assertSame($admin->id, $verification->verified_by);
        $this->assertSame($report->id, $verification->metadata['report_id']);
        $this->assertSame('wrong_info', $verification->metadata['report_type']);
        $this->assertSame(
            (int) config('vegetarian.verification_weights.user_report'),
            $restaurant->fresh()->confidenceScore->score,
        );
    }

    public function test_report_types_mapped_to_null_do_not_add_confidence(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        foreach (['closed', 'other'] as $type) {
            $report = RestaurantReport::factory()->create([
                'restaurant_id' => $restaurant->id,
                'type' => $type,
                'status' => 'pending',
            ]);

            $this->withHeaders($this->headers($admin))
                ->postJson("/api/v1/admin/reports/{$report->id}/approve")
                ->assertOk();
        }

        $this->assertDatabaseCount('restaurant_verifications', 0);
        $this->assertNull($restaurant->fresh()->confidenceScore);
    }

    public function test_rejecting_a_report_records_no_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();
        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'wrong_info',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/reject")
            ->assertOk();

        $this->assertDatabaseCount('restaurant_verifications', 0);
    }

    /**
     * 同一家店被回報很多次不能一路加到滿分——CalculateRestaurantScoreJob 依類型取最高分。
     */
    public function test_many_approved_reports_only_count_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create();

        foreach (['wrong_info', 'wrong_address', 'wrong_hours'] as $type) {
            $report = RestaurantReport::factory()->create([
                'restaurant_id' => $restaurant->id,
                'type' => $type,
                'status' => 'pending',
            ]);

            $this->withHeaders($this->headers($admin))
                ->postJson("/api/v1/admin/reports/{$report->id}/approve")
                ->assertOk();
        }

        $this->assertSame(
            (int) config('vegetarian.verification_weights.user_report'),
            $restaurant->fresh()->confidenceScore->score,
        );
    }

    public function test_demoting_to_friendly_rescores_the_external_source_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create(['source' => 'osm']);
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        DietType::factory()->create(['code' => 'vegan_friendly']);
        $restaurant->dietTypes()->attach($vegan);
        $restaurant->load('dietTypes');

        app(VerificationService::class)->syncExternalSource($restaurant);

        $this->assertSame(
            DietCatalog::externalSourceScore('exclusive'),
            (int) $restaurant->verifications()->where('verification_type', 'external_source')->value('score'),
        );

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'not_vegetarian',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertSame(
            DietCatalog::externalSourceScore('friendly'),
            (int) $restaurant->verifications()->where('verification_type', 'external_source')->value('score'),
        );
    }

    /**
     * 手動建立的店沒有 external_source 紀錄，降級不該憑空幫它生一筆「外部來源」分數。
     */
    public function test_demoting_does_not_invent_an_external_source_verification(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $restaurant = Restaurant::factory()->create(['source' => 'manual']);
        $vegan = DietType::factory()->create(['code' => 'vegan']);
        DietType::factory()->create(['code' => 'vegan_friendly']);
        $restaurant->dietTypes()->attach($vegan);

        $report = RestaurantReport::factory()->create([
            'restaurant_id' => $restaurant->id,
            'type' => 'not_vegetarian',
            'status' => 'pending',
        ]);

        $this->withHeaders($this->headers($admin))
            ->postJson("/api/v1/admin/reports/{$report->id}/approve")
            ->assertOk();

        $this->assertSame(
            0,
            $restaurant->verifications()->where('verification_type', 'external_source')->count(),
        );
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
