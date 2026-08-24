<?php

namespace App\Services\Recommendation;

use App\Models\Restaurant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 見 docs/architecture.md／總體規劃第三十節：
 *
 *   score = distance_score * 0.25 + rating_score * 0.20 + vegetarian_confidence * 0.25
 *         + feature_match * 0.15 + popularity * 0.10 + freshness * 0.05
 *
 * 權重放 config/recommendation.php，這裡不寫死。六個分量都先正規化到 0~1 再加權，
 * 分數本身沒有跨請求的絕對意義（例如「distance_score」是相對於這次候選集裡最遠的那家算的，
 * 不是相對於某個固定公里數），只在同一次候選集內部排序有意義——這跟這個功能的用途一致：
 * 首頁「推薦餐廳」是排序，不是要給使用者看一個可以跨頁比較的絕對分數。
 */
class RuleBasedRecommendationService implements RecommendationServiceInterface
{
    public function rank(Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $weights = config('recommendation.weights');
        $maxFeaturesExpected = config('recommendation.max_features_expected');
        $freshnessWindowDays = config('recommendation.freshness_window_days');

        $maxDistance = max(1.0, $candidates->max(fn (Restaurant $r) => $r->distance ?? 0));
        $maxRatingCount = max(1, $candidates->max('rating_count'));

        return $candidates
            ->each(function (Restaurant $restaurant) use ($weights, $maxFeaturesExpected, $freshnessWindowDays, $maxDistance, $maxRatingCount) {
                $distanceScore = 1 - min(1, ($restaurant->distance ?? 0) / $maxDistance);
                $ratingScore = min(1, $restaurant->rating / 5);
                // Larastan 把 eager-loaded HasOne 誤判成永遠非 null；多數餐廳根本沒有
                // restaurant_confidence_scores 紀錄，實測過真的會是 null，拿掉 ?-> 會在
                // 那些餐廳身上直接炸「Attempt to read property on null」。
                // @phpstan-ignore nullsafe.neverNull
                $vegetarianConfidence = min(1, ($restaurant->confidenceScore?->score ?? 0) / 100);

                $featureCount = $restaurant->dietTypes->count() + $restaurant->features->count();
                $featureMatch = min(1, $featureCount / $maxFeaturesExpected);

                $popularity = min(1, $restaurant->rating_count / $maxRatingCount);

                $ageDays = Carbon::parse($restaurant->created_at)->diffInDays(now());
                $freshness = max(0, 1 - ($ageDays / $freshnessWindowDays));

                $restaurant->recommendation_score = round(
                    $distanceScore * $weights['distance']
                    + $ratingScore * $weights['rating']
                    + $vegetarianConfidence * $weights['vegetarian_confidence']
                    + $featureMatch * $weights['feature_match']
                    + $popularity * $weights['popularity']
                    + $freshness * $weights['freshness'],
                    4
                );
            })
            ->sortByDesc('recommendation_score')
            ->values();
    }
}
