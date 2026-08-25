<?php

namespace App\Http\Resources;

use App\Models\Restaurant;
use App\Support\DietCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Restaurant
 *
 * @property float|null $distance `RestaurantRepository::search()` 的 subquery 計算欄位
 *                                （見 app/Repositories/RestaurantRepository.php 的 selectRaw），只有半徑搜尋時才存在，
 *                                不是 restaurants 表的實際欄位，Restaurant model 本身不會宣告它。
 * @property float|null $recommendation_score `RuleBasedRecommendationService::rank()` 動態
 *                                            設定的分數，只有 GET /restaurants/recommended 才會有。
 */
class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $presentation = $this->relationLoaded('dietTypes')
            ? DietCatalog::venuePresentation($this->dietTypes->pluck('code')->all())
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'district' => $this->district,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'distance_meters' => $this->when(isset($this->distance), fn () => round((float) $this->distance, 1)),
            'recommendation_score' => $this->when(isset($this->recommendation_score), fn () => (float) $this->recommendation_score),
            'phone' => $this->phone,
            'website' => $this->website,
            'price_level' => $this->price_level,
            'rating' => (float) $this->rating,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
            'diet_types' => $this->whenLoaded('dietTypes', fn () => $this->dietTypes->pluck('code')),
            'venue_kind' => $this->when($presentation !== null, $presentation['kind'] ?? null),
            'venue_badge' => $this->when($presentation !== null, $presentation['badge'] ?? null),
            'venue_summary' => $this->when($presentation !== null, $presentation['summary'] ?? null),
            'features' => $this->whenLoaded('features', fn () => $this->features->pluck('code')),
            'menu_items' => MenuItemResource::collection($this->whenLoaded('menuItems')),
            'menu_empty_message' => $this->when(
                $this->relationLoaded('menuItems') && $this->menuItems->isEmpty(),
                fn () => DietCatalog::menuEmptyMessage(
                    is_array($presentation) ? $presentation['kind'] : null,
                    $this->source,
                ),
            ),
            'confidence_score' => $this->whenLoaded('confidenceScore', fn () => $this->confidenceScore?->score),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
