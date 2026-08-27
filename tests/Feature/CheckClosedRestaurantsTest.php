<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\External\BusinessStatus;
use App\Services\External\BusinessStatusProviderInterface;
use App\Services\External\MockBusinessStatusProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 自動下架永久歇業的店（restaurants:check-closed）。
 *
 * 這組測試守的是「不該動的不要動」：只有明確的歇業訊號才下架，
 * Unknown 一律不動手——把還在營業的店從地圖上抹掉，使用者不會回頭來檢查。
 */
class CheckClosedRestaurantsTest extends TestCase
{
    use RefreshDatabase;

    private MockBusinessStatusProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new MockBusinessStatusProvider;
        $this->app->instance(BusinessStatusProviderInterface::class, $this->provider);
        Cache::forget('restaurants:closed-check:cursor');
    }

    public function test_permanently_closed_restaurant_is_deactivated(): void
    {
        $closed = Restaurant::factory()->create(['status' => 'active']);
        $this->provider->setStatus($closed->id, BusinessStatus::ClosedPermanently);

        $this->artisan('restaurants:check-closed')->assertSuccessful();

        // 下架而不是刪除：判斷錯了救得回來，reviews／favorites 的外鍵也不會斷。
        $this->assertSame('inactive', $closed->fresh()->status);
        $this->assertDatabaseCount('restaurants', 1);
    }

    public function test_operational_restaurant_is_left_alone(): void
    {
        $open = Restaurant::factory()->create(['status' => 'active']);
        $this->provider->setStatus($open->id, BusinessStatus::Operational);

        $this->artisan('restaurants:check-closed')->assertSuccessful();

        $this->assertSame('active', $open->fresh()->status);
    }

    public function test_unknown_status_never_deactivates(): void
    {
        // 查不到／超時／來源沒收錄都會回 Unknown。把它當成歇業的話，外部來源的
        // 任何一次閃失都會把還在營業的店抹掉。
        $unknown = Restaurant::factory()->create(['status' => 'active']);

        $this->artisan('restaurants:check-closed')->assertSuccessful();

        $this->assertSame('active', $unknown->fresh()->status);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $closed = Restaurant::factory()->create(['status' => 'active']);
        $this->provider->setStatus($closed->id, BusinessStatus::ClosedPermanently);

        $this->artisan('restaurants:check-closed --dry-run')->assertSuccessful();

        $this->assertSame('active', $closed->fresh()->status);
        // dry-run 不推進游標，否則真的要跑時這批會被跳過。
        $this->assertSame(0, (int) Cache::get('restaurants:closed-check:cursor', 0));
    }

    public function test_cursor_advances_so_the_next_run_checks_different_rows(): void
    {
        $first = Restaurant::factory()->create(['status' => 'active']);
        $second = Restaurant::factory()->create(['status' => 'active']);
        $this->provider->setStatus($first->id, BusinessStatus::Operational);
        $this->provider->setStatus($second->id, BusinessStatus::ClosedPermanently);

        $this->artisan('restaurants:check-closed --limit=1')->assertSuccessful();

        // 第一輪只看第一家，第二家還沒被碰到。
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame($first->id, (int) Cache::get('restaurants:closed-check:cursor'));

        $this->artisan('restaurants:check-closed --limit=1')->assertSuccessful();

        $this->assertSame('inactive', $second->fresh()->status);
    }

    public function test_cursor_resets_after_reaching_the_end(): void
    {
        $only = Restaurant::factory()->create(['status' => 'active']);
        Cache::forever('restaurants:closed-check:cursor', $only->id);

        $this->artisan('restaurants:check-closed')
            ->expectsOutputToContain('游標歸零')
            ->assertSuccessful();

        $this->assertSame(0, (int) Cache::get('restaurants:closed-check:cursor'));
    }

    public function test_specific_ids_can_be_rechecked_regardless_of_cursor(): void
    {
        $target = Restaurant::factory()->create(['status' => 'active']);
        Cache::forever('restaurants:closed-check:cursor', $target->id + 100);
        $this->provider->setStatus($target->id, BusinessStatus::ClosedPermanently);

        $this->artisan("restaurants:check-closed --id={$target->id}")->assertSuccessful();

        $this->assertSame('inactive', $target->fresh()->status);
    }

    public function test_already_inactive_restaurants_are_not_rechecked(): void
    {
        // 已經下架的不必再問外部 API——Google Places 是按請求計費的。
        $inactive = Restaurant::factory()->create(['status' => 'inactive']);
        $this->provider->setStatus($inactive->id, BusinessStatus::ClosedPermanently);

        $this->artisan('restaurants:check-closed')
            ->expectsOutputToContain('沒有需要檢查的餐廳')
            ->assertSuccessful();
    }
}
