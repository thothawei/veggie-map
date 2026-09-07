<?php

namespace Tests\Unit;

use App\Rules\SafeEmail;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * `SafeEmail` 規則本身。
 *
 * **為什麼需要這一組**：升上 Laravel 12.69 之後，預設的 `email` 規則自己就會擋掉
 * `"user\r\n"@example.com`（實測過，2026-09-07），所以
 * `tests/Feature/Api/SafeEmailTest.php` 那種「打 /auth/register 斷言 422」的
 * 端到端測試，**現在把 SafeEmail 拿掉照樣會綠**——它已經不能證明這條規則有效。
 * 那正是那份測試自己註解裡警告過的失敗模式，只是這次是被上游修好而失效的。
 *
 * 這裡直接對規則本身斷言：把 `SafeEmail::validate()` 的實作清空，這幾條會紅。
 */
class SafeEmailRuleTest extends TestCase
{
    /** @param  mixed  $value */
    private function fails($value): bool
    {
        return Validator::make(['email' => $value], ['email' => [new SafeEmail]])->fails();
    }

    public function test_rejects_crlf_in_a_quoted_local_part(): void
    {
        $this->assertTrue($this->fails("\"user\r\n\"@example.com"));
        $this->assertTrue($this->fails("\"user\r\nBcc: victim@example.com\"@example.com"));
    }

    /** \r \n 是 header injection 的載體，其餘 C0 控制字元在位址裡本來就不合法。 */
    public function test_rejects_other_control_characters(): void
    {
        $this->assertTrue($this->fails("user\x00@example.com"));
        $this->assertTrue($this->fails("user\x1F@example.com"));
        $this->assertTrue($this->fails("user\x7F@example.com"));
    }

    public function test_accepts_a_normal_address(): void
    {
        $this->assertFalse($this->fails('user@example.com'));
        $this->assertFalse($this->fails('user+tag@sub.example.co.jp'));
    }

    /**
     * 非字串直接放行——型別是 `string` 規則的職責，這條規則只管控制字元。
     * 兩件事混在一起會讓錯誤訊息說謊（陣列被說成「email 格式錯誤」）。
     */
    public function test_ignores_non_string_values(): void
    {
        $this->assertFalse($this->fails(['user@example.com']));
        $this->assertFalse($this->fails(123));
    }
}
