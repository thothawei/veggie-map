<?php

namespace App\Http\Resources;

use App\Models\Restaurant;
use App\Support\CuisineCatalog;
use App\Support\DietCatalog;
use App\Support\OpeningStatus;
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

        // 只有載入關聯時才算，避免列表 API 逐筆補查（N+1）。search()／findForDetail()
        // 都有 eager load，沒載到就代表呼叫端刻意不要這段資料。
        $opening = $this->relationLoaded('openingHours') ? OpeningStatus::for($this->resource) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // 列表沒有撈 description（見 RestaurantRepository::LIST_COLUMNS）。
            // whenHas＝沒撈到就整個 key 不出現，而不是回 null——回 null 等於宣稱
            // 「這家店沒有描述」，那是安靜地說謊。
            'description' => $this->whenHas('description'),
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
            'cuisines' => $this->whenLoaded('features', fn () => $this->features
                ->filter(fn ($feature) => CuisineCatalog::isCuisine($feature->code))
                ->map(fn ($feature) => [
                    'code' => $feature->code,
                    'label' => CuisineCatalog::label($feature->code) ?? $feature->label,
                ])
                ->values()
                ->all()),
            'features' => $this->whenLoaded('features', fn () => $this->features
                ->reject(fn ($feature) => CuisineCatalog::isCuisine($feature->code))
                ->pluck('code')),
            'menu_items' => MenuItemResource::collection($this->whenLoaded('menuItems')),
            'menu_empty_message' => $this->when(
                $this->relationLoaded('menuItems') && $this->menuItems->isEmpty(),
                fn () => DietCatalog::menuEmptyMessage(
                    is_array($presentation) ? $presentation['kind'] : null,
                    $this->source,
                ),
            ),
            // 三態：open／closed／unknown。unknown 是 OSM 最常見的情況，不要壓成 closed。
            'open_status' => $this->when($opening !== null, fn () => $opening['status']),
            'open_now' => $this->when($opening !== null, fn () => $opening['open_now']),
            'closes_at' => $this->when($opening !== null && $opening['closes_at'] !== null, fn () => $opening['closes_at']),
            'next_opens_at' => $this->when($opening !== null && $opening['opens_at'] !== null, fn () => $opening['opens_at']),
            'opening_hours_raw' => $this->whenHas('opening_hours'),
            'opening_hours_week' => $this->when(
                $opening !== null && $this->openingHours->isNotEmpty(),
                fn () => OpeningStatus::week($this->resource),
            ),
            'confidence_score' => $this->whenLoaded('confidenceScore', fn () => $this->confidenceScore?->score),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
