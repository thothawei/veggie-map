<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * `/horizon` 與 `/telescope` 在非 local 環境由 Gate 守著。這兩個頁面看得到佇列
 * payload、SQL bindings 與請求內文，放行條件錯了是資料外洩，不是 UI 問題。
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // gate() 是 protected，正常由 provider 的 boot 呼叫。這裡直接註冊，測的是
        // 那個 closure 的判斷，不是 provider 的啟動時機。
        $this->app->register(HorizonServiceProvider::class);
        $this->app->register(TelescopeServiceProvider::class);
    }

    /**
     * 預設沒有人看得到。空白名單若被誤解成「不限制」，production 的儀表板就對外開著。
     */
    public function test_nobody_passes_when_the_allowlist_is_empty(): void
    {
        Config::set('veggiemap.dashboards.allowed_emails', []);
        $user = User::factory()->create(['email' => 'admin@example.com', 'role' => 'admin']);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($user)->allows('viewTelescope'));
    }

    public function test_listed_email_passes(): void
    {
        Config::set('veggiemap.dashboards.allowed_emails', ['ops@example.com']);
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($user)->allows('viewTelescope'));
    }

    public function test_unlisted_email_does_not_pass_even_for_an_admin(): void
    {
        Config::set('veggiemap.dashboards.allowed_emails', ['ops@example.com']);
        $user = User::factory()->create(['email' => 'someone@example.com', 'role' => 'admin']);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($user)->allows('viewTelescope'));
    }

    /**
     * Horizon 的 gate 收得到 null（未登入）。`optional($user)->email` 會是 null，
     * 鬆散的 in_array 在某些型別下會把它當成命中。
     */
    public function test_guest_does_not_pass_horizon(): void
    {
        Config::set('veggiemap.dashboards.allowed_emails', ['ops@example.com']);

        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }

    /**
     * 設定是逗號分隔的字串，空白與多餘的逗號要吃掉——否則 `a@b.c, d@e.f` 的第二個
     * 會變成 ` d@e.f`，本人反而進不去。
     *
     * 直接 require 那個設定檔，不是在測試裡重寫一遍同樣的運算式（那樣把
     * config/veggiemap.php 刪掉測試還是綠的，等於什麼都沒守）。
     */
    public function test_config_parses_a_comma_separated_env_value(): void
    {
        $original = $_SERVER['DASHBOARD_ALLOWED_EMAILS'] ?? null;
        $_SERVER['DASHBOARD_ALLOWED_EMAILS'] = 'ops@example.com, dev@example.com,,';

        try {
            $config = require config_path('veggiemap.php');
        } finally {
            if ($original === null) {
                unset($_SERVER['DASHBOARD_ALLOWED_EMAILS']);
            } else {
                $_SERVER['DASHBOARD_ALLOWED_EMAILS'] = $original;
            }
        }

        $this->assertSame(
            ['ops@example.com', 'dev@example.com'],
            $config['dashboards']['allowed_emails'],
        );
    }
}
