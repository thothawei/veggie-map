<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
