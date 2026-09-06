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
     * 可信度的三段標籤（高度可信／有查證／待確認），取代畫面上的裸分數。
     *
     * 為什麼不直接印分數：0–100 看起來像評分，而這個產品刻意不做評分制度。
     * 使用者會把「素食可信度 5」讀成「這家店 5 分，很爛」，實際意思卻是
     * 「只有 OSM 標示，還沒有人查證過」——那是兩件完全不同的事。
     *
     * 沒有分數列（null）與 0 分都算最低那一段：兩者在使用者眼裡是同一件事
     * （沒有人查證過），分開講沒有意義。門檻與 confidence_filters 同源，見 config。
     *
     * @return array{code: string, label: string}
     */
    public static function level(?int $score): array
    {
        /** @var list<array<string, mixed>> $levels */
        $levels = config('vegetarian.confidence_levels', []);
        $score ??= 0;

        foreach ($levels as $level) {
            if ($score >= (int) $level['min']) {
                return ['code' => (string) $level['code'], 'label' => (string) $level['label']];
            }
        }

        // config 被改成沒有 min=0 的那一段時才會走到這裡。回一個誠實的預設，
        // 而不是讓畫面上出現空白徽章。
        return ['code' => 'unverified', 'label' => '素食資訊待確認'];
    }

    /**
     * @return list<string>
     */
    public static function adminTypeCodes(): array
    {
        return array_column(self::adminTypes(), 'code');
    }
}
