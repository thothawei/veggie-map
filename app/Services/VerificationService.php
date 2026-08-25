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

            // 逐筆 delete（不是 whereIn 的 query delete）才會觸發
            // RestaurantVerificationObserver 重算分數——直接下 query delete 的話，
            // 被刪掉的那筆 10 分還會留在剛剛算出來的 confidence score 裡。
            $existing->skip(1)->each(fn (RestaurantVerification $extra) => $extra->delete());

            return $keep->refresh();
        }

        return $this->record($restaurant, 'external_source', scoreOverride: $score);
    }

    /**
     * 已經有 external_source 的店（＝從 OSM 匯入的）在 diet 變動後重算那一筆的分數。
     * 沒有紀錄就什麼都不做——手動建立的店不該因為改了 diet 就憑空拿到「外部來源」分數。
     */
    public function rescoreExternalSourceIfPresent(Restaurant $restaurant): ?RestaurantVerification
    {
        $hasExternalSource = $restaurant->verifications()
            ->where('verification_type', 'external_source')
            ->exists();

        if (! $hasExternalSource) {
            return null;
        }

        return $this->syncExternalSource($restaurant);
    }
}
