<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBoundingBox;
use App\Models\Feature;
use App\Support\DietCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchRestaurantRequest extends FormRequest
{
    use ValidatesBoundingBox;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'keyword' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'radius' => ['nullable', 'numeric', 'min:0.1', 'max:50'],
            // "minLat,minLng,maxLat,maxLng"。城市範圍是矩形，用 bbox 比「中心點＋半徑」
            // 精準，也不受 radius 上限 50km 限制（台中半對角線就 59.6km）。
            'bbox' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            // 單一 code 或逗號分隔的多個（`?diet=vegan,ovo_lacto`，彼此是 OR）。
            // 用自訂檢查而不是 `exists`：後者只認單一值，改成多選之後
            // `vegan,ovo_lacto` 會被判成不存在的 code 而回 422。
            'diet' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $codes = array_map('trim', explode(',', (string) $value));
                $known = DietCatalog::codes();

                foreach ($codes as $code) {
                    if ($code === '' || ! in_array($code, $known, true)) {
                        $fail("{$attribute} 含有未知的飲食類型：{$code}");

                        return;
                    }
                }
            }],
            'price_level' => ['nullable', 'integer', 'between:1,4'],
            'rating_min' => ['nullable', 'numeric', 'between:0,5'],
            // 只留下「此刻在該店當地時間營業中」的餐廳。沒有可解析營業時間的店
            // 不會被算成營業中（見 RestaurantRepository::applyOpenNow）。
            'open_now' => ['nullable', 'boolean'],
            // 素食可信度下限（0–100，見 config/vegetarian.php）。「只看有證據的店」。
            'confidence_min' => ['nullable', 'integer', 'between:0,100'],
            // relevance＝關鍵字相關性（店名 > 菜色／料理 > 地區 > 描述），只有帶
            // keyword 才有意義；沒帶時明確回 422，不要悄悄退回其他排序。
            'sort' => ['nullable', Rule::in(['relevance', 'confidence', 'distance', 'rating', 'popular', 'newest'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string'],
            DietCatalog::venueScopeParam() => ['nullable', 'string', Rule::in(DietCatalog::venueScopeKeys())],
        ];

        // 每個 features.code 都是獨立的布林篩選（`?takeout=1&wifi=1`），與既有
        // pet_friendly／parking 同一套約定。寫死兩個的話，OSM 匯入最多的 takeout／wifi
        // 根本沒有查詢入口。
        return $rules + Feature::booleanFilterRules();
    }

    /**
     * axios 會把布林序列化成 `"true"`，Laravel 的 boolean 規則不吃。前端已在邊界轉成
     * `1`，這裡再收一次 `"true"`／`"false"`，避免其他 API 使用端踩同一個坑。
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $normalized = Feature::normalizeBooleanInputs($input);

        // open_now 不是 features.code，但走同一條 querystring 約定，同樣要收 "true"/"false"。
        if (array_key_exists('open_now', $input)) {
            if ($input['open_now'] === 'true') {
                $normalized['open_now'] = '1';
            } elseif ($input['open_now'] === 'false') {
                $normalized['open_now'] = '0';
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
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

            if ($this->input('sort') === 'relevance' && ! $this->filled('keyword')) {
                $validator->errors()->add('sort', 'sort=relevance requires keyword.');
            }

            $this->validateBboxIfPresent($validator);
        });
    }
}
