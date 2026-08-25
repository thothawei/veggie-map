<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // "minLat,minLng,maxLat,maxLng"。城市範圍是矩形，用 bbox 比「中心點＋半徑」
            // 精準，也不受 radius 上限 50km 限制（台中半對角線就 59.6km）。
            'bbox' => ['nullable', 'string'],
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

            // 格式錯的 bbox 不能靜默忽略——那會從「查這座城市」變成「查全世界」，
            // 使用者只會看到莫名其妙的結果而不是錯誤。
            if ($this->filled('bbox')) {
                $this->validateBbox($validator, (string) $this->input('bbox'));
            }
        });
    }

    private function validateBbox(Validator $validator, string $bbox): void
    {
        $parts = array_map('trim', explode(',', $bbox));

        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            $validator->errors()->add('bbox', 'bbox must be "minLat,minLng,maxLat,maxLng".');

            return;
        }

        [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $parts);

        if ($minLat < -90 || $maxLat > 90 || $minLng < -180 || $maxLng > 180) {
            $validator->errors()->add('bbox', 'bbox coordinates are out of range.');

            return;
        }

        // 顛倒的角落會產生一個面積為零或負的矩形，MBRContains 只會安靜地回傳零筆，
        // 看起來像「這個城市沒有餐廳」而不是「參數寫反了」。
        if ($minLat >= $maxLat || $minLng >= $maxLng) {
            $validator->errors()->add('bbox', 'bbox min corner must be south-west of the max corner.');
        }
    }
}
