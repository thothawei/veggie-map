<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 註冊／登入端點不接受含控制字元的 email（CVE-2026-48019 的形狀）。
 *
 * **2026-09-07 升上 Laravel 12.69.1 之後，這三條的性質變了**：預設 `email` 規則
 * 自己就會擋下這些 payload（實測過），所以**把 `SafeEmail` 從 FormRequest 拿掉，
 * 這三條照樣會綠**——反向驗證確認過（把規則的實作停用，紅的是
 * `tests/Unit/SafeEmailRuleTest.php` 的兩條，這裡三條全綠）。
 *
 * 它們現在是**端點層的回歸測試**（「這兩個端點不會接受這種 email」，不管是哪一道
 * 擋的），不是 `SafeEmail` 的證明。要釘住那條規則本身，看 `SafeEmailRuleTest`。
 *
 * **payload 是實測出來的，不是想像的**：第一版測試用
 * `user@example.com\r\nBcc: ...`，但那個字串連當時的 Laravel 11.56 都會擋。
 * 實際能通過當時預設規則的是**帶引號的 local part**：`"user\r\n"@example.com`
 * （RFC 5321 的 quoted-string 允許裡面出現這些字元）。
 */
class SafeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_rejects_an_email_containing_crlf(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => '測試',
            'email' => "\"user\r\n\"@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422);
    }

    public function test_login_rejects_an_email_containing_crlf(): void
    {
        // 登入失敗本來就回 422（「帳密不正確」也是丟 ValidationException），所以
        // 只斷言狀態碼是假保護——要斷言它是**被驗證規則擋下**，訊息才會是
        // email 格式錯誤而不是帳密錯誤。
        $response = $this->postJson('/api/v1/auth/login', [
            // 與上面同一種形狀（實測過會通過預設規則）；空白等其他字元會被
            // 預設規則擋掉，用那些當 payload 的測試是假保護。
            'email' => "\"user\r\nX\"@example.com",
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString(
            'credentials are incorrect',
            (string) $response->getContent(),
            '應該在驗證階段就被擋下，不是走到比對密碼那一步',
        );
    }

    public function test_a_normal_email_still_registers(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => '測試',
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }
}
