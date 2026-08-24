<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'diet' => ['nullable', 'string', 'exists:diet_types,code'],
            'price_level' => ['nullable', 'integer', 'between:1,4'],
            'rating_min' => ['nullable', 'numeric', 'between:0,5'],
            'pet_friendly' => ['nullable', 'boolean'],
            'parking' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['distance', 'rating', 'popular', 'newest'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string'],
        ];
    }

    /**
     * `sort=distance` 只有帶座標才有意義；沒帶座標卻明確要求 distance 排序視為
     * 使用端誤用，回 422 而不是悄悄退回其他排序。
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('sort') === 'distance' && ! $this->filled('latitude')) {
                $validator->errors()->add('sort', 'sort=distance requires latitude and longitude.');
            }
        });
    }
}
