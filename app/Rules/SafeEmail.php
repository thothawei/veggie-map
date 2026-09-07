<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CVE-2026-48019 / GHSA-5vg9-5847-vvmq 的緩解：Laravel 預設 `email` 規則接受
 * 含 CRLF 的值，可被用來做 header injection（受影響版本 <12.60.0）。
 *
 * **2026-09-07 已升級到 Laravel 12.69.1，上游那個洞本身已經補掉**——實測預設
 * `email` 規則現在會擋下 `"user\r\n"@example.com`。這條規則**保留**作為第二道：
 * 它擋的是所有 C0 控制字元，不綁定某一版框架的實作細節，而且 `email` 規則的
 * 行為以後還可能因為 RFC 模式（`email:rfc`／`strict`）的選擇而變。
 *
 * 但要誠實標明它現在的地位：**它已經不是唯一防線**。所以
 * `tests/Feature/Api/SafeEmailTest.php` 那種端到端斷言現在拿掉這條規則照樣會綠，
 * 真正釘住這條規則的是 `tests/Unit/SafeEmailRuleTest.php`（直接對規則斷言）。
 *
 * 新增任何吃 email 的端點時仍然一起掛上——規則抽成類別就是為了讓「有沒有掛」
 * 在 code review 時看得見。
 */
class SafeEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // \r \n 是 header injection 的載體；其餘 C0 控制字元在 email 位址裡本來就
        // 不合法，一併擋掉比逐一列舉安全。
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $fail('validation.email')->translate();
        }
    }
}
