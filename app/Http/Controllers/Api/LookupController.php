<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DietTypeResource;
use App\Http\Resources\FeatureResource;
use App\Models\DietType;
use App\Models\Feature;
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
        ]);
    }

    public function features(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => FeatureResource::collection(Feature::orderBy('id')->get())->resolve(),
        ]);
    }
}
