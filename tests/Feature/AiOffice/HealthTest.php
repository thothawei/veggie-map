<?php

namespace Tests\Feature\AiOffice;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 驗收：AI Office 的 readiness 端點必須「真的去連」而不是回報設定檔，
 * 而且不是註冊過就看得到（規格第 52／53、74 節）。
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_health(): void
    {
        $this->getJson('/api/v1/ai-office/health')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_plain_consumer_role_cannot_read_health(): void
    {
        // `user` 是只用餐廳地圖的一般消費者，不該看到基礎設施狀態。
        //
        // 這裡斷言 HTTP_ERROR 不是筆誤：Laravel 的 Handler::render() 會先跑
        // prepareException() 把 AuthorizationException 轉成 AccessDeniedHttpException，
        // 才輪到 ApiExceptionRenderer，所以那支 renderer 裡的 FORBIDDEN 分支其實是
        // 死程式碼，全站 403 目前一律回 HTTP_ERROR（docs/openapi.yaml 卻宣告了 FORBIDDEN，
        // 兩邊對不上）。這是既有問題，不在 AI Office Phase 1 的範圍內，這裡先如實鎖住
        // 現況；等到修 renderer 時這個測試會紅，那正是提醒要一起更新的地方。
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->getJson('/api/v1/ai-office/health')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'HTTP_ERROR');
    }

    public function test_viewer_can_read_health_and_all_checks_pass(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->getJson('/api/v1/ai-office/health')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database.ok', true)
            ->assertJsonPath('data.checks.redis.ok', true)
            ->assertJsonPath('data.checks.queue.ok', true)
            ->assertJsonPath('data.checks.workspace.ok', true);

        // 真的量到的東西：資料庫名稱必須是當下這條連線用的測試庫，不是寫死字串。
        $response->assertJsonPath('data.checks.database.database', config('database.connections.mysql.database'));
        $this->assertIsFloat($response->json('data.checks.database.latency_ms'));
    }

    public function test_admin_can_read_health_without_being_listed_explicitly(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/v1/ai-office/health')
            ->assertStatus(200);
    }

    public function test_health_reports_limits_from_config_instead_of_hardcoded_values(): void
    {
        // 反向驗證：改 config 之後回應必須跟著變。如果是寫死的，這個測試會紅。
        config(['ai_office.limits.max_agent_steps' => 7]);

        $this->actingAs(User::factory()->create(['role' => 'manager']))
            ->getJson('/api/v1/ai-office/health')
            ->assertStatus(200)
            ->assertJsonPath('data.limits.max_agent_steps', 7);
    }

    public function test_health_never_leaks_the_api_key(): void
    {
        config(['ai_office.llm.providers.claude.api_key' => 'sk-ant-should-never-appear']);

        $response = $this->actingAs(User::factory()->create(['role' => 'developer']))
            ->getJson('/api/v1/ai-office/health')
            ->assertStatus(200)
            ->assertJsonPath('data.llm.api_key_configured', true);

        $this->assertStringNotContainsString('sk-ant-should-never-appear', $response->getContent());
    }

    public function test_broken_dependency_reports_degraded_with_503(): void
    {
        // 反向驗證：把 workspace 指到不存在的路徑，端點必須誠實說壞掉，
        // 不是永遠回 ok。這也證明 workspace 檢查真的有在檢查。
        config(['ai_office.workspace_root' => base_path('workspace/does-not-exist')]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/v1/ai-office/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.workspace.ok', false)
            ->assertJsonPath('data.checks.database.ok', true);
    }
}
