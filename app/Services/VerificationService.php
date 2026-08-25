<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantVerification;
use App\Models\User;
use App\Support\DietCatalog;
use Illuminate\Support\Carbon;

/**
 * `restaurant_verifications.score` 預設從 `config('vegetarian.verification_weights')`
 * 查表帶入。OSM 匯入的 `external_source` 例外：分數依店家 venue kind 走 `config/diet.php`，
 * 友善店不該拿到「店家明確標示素食」那一檔。
 */
class VerificationService
{
    public function record(
        Restaurant $restaurant,
        string $type,
        ?User $verifiedBy = null,
        ?array $metadata = null,
        ?Carbon $expiresAt = null,
        ?int $scoreOverride = null,
    ): RestaurantVerification {
        $score = $scoreOverride ?? (int) config("vegetarian.verification_weights.{$type}", 0);

        return $restaurant->verifications()->create([
            'verification_type' => $type,
            'score' => $score,
            'verified_by' => $verifiedBy?->id,
            'verified_at' => now(),
            'expires_at' => $expiresAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * OSM 同步專用：依目前 diet 關聯更新或建立唯一一筆 external_source。
     * 重跑時必須改分數（東京友善店先前被錯標 exclusive 會是 10 分），不能只在「沒有紀錄」時插入。
     * 同一類型若已有多筆（早期每日 sync 各寫一筆），Job 會取 max——留下 10 分的舊列
     * 會把友善店的 5 分蓋掉，所以多的要收成一筆。
     */
    public function syncExternalSource(Restaurant $restaurant): RestaurantVerification
    {
        $kind = DietCatalog::venueKindFromCodes($restaurant->dietTypes->pluck('code')->all());
        $score = DietCatalog::externalSourceScore($kind);

        $existing = $restaurant->verifications()
            ->where('verification_type', 'external_source')
            ->orderBy('id')
            ->get();

        if ($existing->isNotEmpty()) {
            $keep = $existing->first();
            $keep->update([
                'score' => $score,
                'verified_at' => now(),
            ]);

            $extraIds = $existing->skip(1)->pluck('id');

            if ($extraIds->isNotEmpty()) {
                $restaurant->verifications()->whereIn('id', $extraIds)->delete();
            }

            return $keep->refresh();
        }

        return $this->record($restaurant, 'external_source', scoreOverride: $score);
    }
}
