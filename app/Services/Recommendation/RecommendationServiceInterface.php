<?php

namespace App\Services\Recommendation;

use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * 見 docs/architecture.md「AI 預留」：第一版只有 RuleBasedRecommendationService，
 * 未來加 AIRecommendationService 時呼叫端（RestaurantController）不需要改，
 * 兩者符合同一介面——Adapter Pattern 在推薦系統上的延伸應用。
 */
interface RecommendationServiceInterface
{
    /**
     * @param  Collection<int, Restaurant>  $candidates  候選集合，通常是同一個半徑搜尋
     *                                                   結果，已經 eager load
     *                                                   dietTypes/features/confidenceScore
     * @return Collection<int, Restaurant> 依 recommendation_score 由高到低排序，每筆
     *                                     Restaurant 都會多一個動態屬性 recommendation_score
     */
    public function rank(Collection $candidates): Collection;
}
