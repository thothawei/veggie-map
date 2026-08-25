<?php

namespace App\Support;

/**
 * config/vegetarian.php 的驗證設定包一層，讓 FormRequest、Admin lookup、前端下拉
 * 讀同一份清單——跟 DietCatalog 對 config/diet.php 的關係一樣，不在多處各寫一份 enum。
 */
class VerificationCatalog
{
    /**
     * @return list<array{code: string, label: string, score: int}>
     */
    public static function adminTypes(): array
    {
        /** @var list<array<string, mixed>> $types */
        $types = config('vegetarian.admin_verifiable_types', []);

        return array_map(fn (array $type): array => [
            'code' => (string) $type['code'],
            'label' => (string) $type['label'],
            'score' => (int) config('vegetarian.verification_weights.'.$type['code'], 0),
        ], $types);
    }

    /**
     * @return list<string>
     */
    public static function adminTypeCodes(): array
    {
        return array_column(self::adminTypes(), 'code');
    }
}
