<?php

namespace App\Http\Resources;

use App\Models\DietType;
use App\Support\DietCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DietType */
class DietTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $code = $this->code;
        $config = collect(DietCatalog::types())->firstWhere('code', $code);

        return [
            'code' => $code,
            'label' => $this->label,
            'kind' => $config['kind'] ?? null,
            'group_label' => $config['group_label'] ?? null,
        ];
    }
}
