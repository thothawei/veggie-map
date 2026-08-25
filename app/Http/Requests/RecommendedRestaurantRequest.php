<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBoundingBox;
use App\Models\Feature;
use Illuminate\Foundation\Http\FormRequest;

class RecommendedRestaurantRequest extends FormRequest
{
    use ValidatesBoundingBox;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:50'],
            'bbox' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
            'diet' => ['nullable', 'string', 'exists:diet_types,code'],
        ] + Feature::booleanFilterRules();
    }

    protected function prepareForValidation(): void
    {
        $normalized = Feature::normalizeBooleanInputs($this->all());

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateBboxIfPresent($validator);
        });
    }
}
