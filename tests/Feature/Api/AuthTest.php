<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.email', 'test@example.com')
            ->assertJsonPath('data.user.role', 'user') // regression: 曾經回 null，見 progress.md
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'role' => 'user']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_login_with_correct_credentials_returns_token(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'login2@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login2@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_me_without_token_returns_401_not_500(): void
    {
        // regression: guest middleware 曾經想導去不存在的 `login` route，回 500
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_with_token_returns_current_user(): void
    {
        $user = User::factory()->create();

        $this->withHeaders(['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();

        // Laravel 測試環境裡同一個 test method 內的多次請求共用同一個 app 容器，
        // sanctum guard 實例會把第一次解析出的 user 快取在物件屬性裡，不會因為 DB
        // 裡的 token 被刪就重新查——這在真實環境（每個請求獨立 process）不會發生，
        // 但測試裡要手動清掉才能驗證「token 真的被撤銷」。
        app('auth')->forgetGuards();

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertStatus(401);
    }

    /**
     * 2026-09-06 使用者決定：正式營運前加過期時間，不再永不過期
     * （`config/sanctum.php` 的 `expiration`）。
     */
    public function test_login_response_reports_when_the_token_expires(): void
    {
        config(['sanctum.expiration' => 60]);

        User::factory()->create(['email' => 'expiring@example.com', 'password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'expiring@example.com',
            'password' => 'password123',
        ])->assertOk();

        $expiresAt = $response->json('data.expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertEqualsWithDelta(
            now()->addMinutes(60)->timestamp,
            Carbon::parse($expiresAt)->timestamp,
            5,
        );
    }

    public function test_expires_at_is_null_when_expiration_is_disabled(): void
    {
        config(['sanctum.expiration' => null]);

        User::factory()->create(['email' => 'forever@example.com', 'password' => 'password123']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'forever@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.expires_at', null);
    }

    public function test_a_token_older_than_the_expiration_window_is_rejected(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // 直接把 token 的 created_at 往回推，模擬「已經超過過期上限」，
        // 不用真的等 60 分鐘。
        $user->tokens()->first()->forceFill(['created_at' => now()->subMinutes(61)])->save();

        app('auth')->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/me')
            ->assertStatus(401);
    }

    public function test_a_token_within_the_expiration_window_still_works(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $user->tokens()->first()->forceFill(['created_at' => now()->subMinutes(30)])->save();

        app('auth')->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    /**
     * refresh 讓活躍使用者在過期前換一張新 token，不用整個重新登入。
     */
    public function test_refresh_issues_a_new_token_and_revokes_the_old_one(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $oldToken = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$oldToken])
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $newToken = $response->json('data.token');
        $this->assertNotSame($oldToken, $newToken);

        app('auth')->forgetGuards();

        // 舊 token 立刻失效——refresh 是「換」不是「多發一張」，外流的舊 token
        // 撤銷才有意義。
        $this->withHeaders(['Authorization' => 'Bearer '.$oldToken])
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        app('auth')->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$newToken])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_refresh_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }
}
