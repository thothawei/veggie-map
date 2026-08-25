<?php

namespace App\Http\Resources;

use App\Models\MenuItem;
use App\Support\DietCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MenuItem */
class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price === null ? null : (float) $this->price,
            'diet_type' => $this->diet_type,
            'diet_label' => DietCatalog::menuItemDietLabel($this->diet_type),
            'is_available' => $this->is_available,
        ];
    }
}
