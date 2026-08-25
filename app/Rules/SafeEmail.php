<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CVE-2026-48019 / GHSA-5vg9-5847-vvmq 的緩解：Laravel 預設 `email` 規則接受
 * 含 CRLF 的值，可被用來做 header injection（受影響版本 <12.60.0；本專案目前是
 * Laravel 11.56）。
 *
 * 正式修法是升到 12.61.1+／13.12+，那是 major upgrade，不在這次範圍（見
 * docs/deployment.md）。在升級之前，凡是會進到郵件路徑的 email 欄位都掛這條規則，
 * 把控制字元擋在 FormRequest。
 *
 * 這是**緩解不是修補**：它只保護有掛規則的欄位。新增任何吃 email 的端點時要記得
 * 一起掛上——所以規則本身抽成一個類別，讓「有沒有掛」在 code review 時看得見。
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
