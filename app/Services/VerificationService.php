<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantVerification;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * `restaurant_verifications.score` 一律從 `config('vegetarian.verification_weights')`
 * 查表帶入，不讓呼叫端自己決定分數（見 docs/database.md）。目前還沒有任何 HTTP 端點會
 * 呼叫這個 service——寫入驗證紀錄的實際來源（餐廳自主認領／Admin 後台／Phase 8 的
 * `restaurants:sync` 外部資料匯入）都還沒實作，這裡先把「一筆驗證怎麼決定分數」的邏輯
 * 集中定義好，供之後那些呼叫端共用。
 */
class VerificationService
{
    public function record(
        Restaurant $restaurant,
        string $type,
        ?User $verifiedBy = null,
        ?array $metadata = null,
        ?Carbon $expiresAt = null,
    ): RestaurantVerification {
        $score = (int) config("vegetarian.verification_weights.{$type}", 0);

        return $restaurant->verifications()->create([
            'verification_type' => $type,
            'score' => $score,
            'verified_by' => $verifiedBy?->id,
            'verified_at' => now(),
            'expires_at' => $expiresAt,
            'metadata' => $metadata,
        ]);
    }
}
