<?php

namespace App\Support;

use App\Models\RestaurantVerification;
use Illuminate\Support\Collection;

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
     * 這家店的可信度是「憑什麼」——每一種已成立的驗證各取最高分（跟
     * `CalculateRestaurantScoreJob` 的加總規則一致：同一類型多筆是重複證據，
     * 不能每筆都加）。
     *
     * 兩邊的規則必須一樣，否則畫面上的明細加起來會跟總分對不上——那比不顯示明細
     * 更傷信任。
     *
     * @param  Collection<int, RestaurantVerification>  $verifications
     * @return list<array{code: string, label: string, score: int}>
     */
    public static function breakdown($verifications): array
    {
        /** @var array<string, string> $labels */
        $labels = config('vegetarian.verification_labels', []);
        $now = now();

        return $verifications
            // 過期的驗證不算——「三年前有人回報過」不該一直撐著分數。
            ->filter(function (RestaurantVerification $verification) use ($now): bool {
                $expiresAt = $verification->expires_at;

                return $expiresAt === null || $expiresAt->greaterThan($now);
            })
            ->groupBy('verification_type')
            ->map(fn ($rows, string $type) => [
                'code' => $type,
                'label' => $labels[$type] ?? $type,
                'score' => (int) $rows->max('score'),
            ])
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function adminTypeCodes(): array
    {
        return array_column(self::adminTypes(), 'code');
    }
}
