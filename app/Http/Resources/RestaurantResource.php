<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Restaurant */
class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'phone' => $this->phone,
            'website' => $this->website,
            'price_level' => $this->price_level,
            'rating' => (float) $this->rating,
            'rating_count' => $this->rating_count,
            'status' => $this->status,
            'diet_types' => $this->whenLoaded('dietTypes', fn () => $this->dietTypes->pluck('code')),
            'features' => $this->whenLoaded('features', fn () => $this->features->pluck('code')),
            'menu_items' => MenuItemResource::collection($this->whenLoaded('menuItems')),
            'confidence_score' => $this->whenLoaded('confidenceScore', fn () => $this->confidenceScore?->score),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
