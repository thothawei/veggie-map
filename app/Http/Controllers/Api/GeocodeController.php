<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeocodeRequest;
use App\Http\Resources\GeocodedPlaceResource;
use App\Services\External\GeocodingProviderInterface;
use App\Services\External\GeocodingUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * 使用者主動輸入地名/地標的搜尋框，見 docs/external-apis.md：Nominatim 每秒最多 1 次請求，
 * 同一查詢字串 cache 1 天，避免使用者重複打同一個地名時每次都真的呼叫 Nominatim。
 */
class GeocodeController extends Controller
{
    public function __construct(private readonly GeocodingProviderInterface $geocoder) {}

    public function search(GeocodeRequest $request): JsonResponse
    {
        $query = $request->validated('q');
        $cacheKey = 'geocode:'.md5(mb_strtolower(trim($query)));

        try {
            // 失敗不能寫進 cache：Nominatim 503 回空陣列，remember 會把「這串字找不到」
            // 存一天，真正的地點整天都搜不到。空結果（200 + []）仍可 cache。
            $places = Cache::remember($cacheKey, now()->addDay(), function () use ($query) {
                return $this->geocoder->search($query);
            });
        } catch (GeocodingUnavailableException) {
            $places = [];
        }

        return response()->json([
            'success' => true,
            'data' => GeocodedPlaceResource::collection($places)->resolve(),
        ]);
    }
}
