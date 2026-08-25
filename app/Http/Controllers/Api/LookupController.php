<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DietTypeResource;
use App\Http\Resources\FeatureResource;
use App\Models\DietType;
use App\Models\Feature;
use App\Support\DietCatalog;
use Illuminate\Http\JsonResponse;

/**
 * `diet_types`／`features` 是固定清單（見 docs/database.md），筆數小到不需要分頁，
 * 也不分主/從資源各開一個 controller。
 */
class LookupController extends Controller
{
    public function dietTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => DietTypeResource::collection(DietType::orderBy('id')->get())->resolve(),
            'meta' => [
                'venue_scope' => DietCatalog::venueScopeMeta(),
            ],
        ]);
    }

    public function features(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FeatureResource::collection(Feature::orderBy('id')->get())->resolve(),
        ]);
    }

    /**
     * 地圖可切換的城市。同樣是固定清單，來源是 config/cities.php——不從 restaurants 表
     * 的 city 欄位歸納，因為那個欄位有 59% 是空的、同一個城市還有「臺中市」「台中市」
     * 兩種寫法，東京的節點填的是「渋谷区」這類行政區而不是都名（2026-08-25 實測）。
     */
    public function cities(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => config('cities'),
        ]);
    }
}
