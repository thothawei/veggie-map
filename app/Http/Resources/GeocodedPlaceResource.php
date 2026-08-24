<?php

namespace App\Http\Resources;

use App\Services\External\GeocodedPlace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GeocodedPlace
 */
class GeocodedPlaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'display_name' => $this->displayName,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
