<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantResource;
use App\Models\Favorite;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $restaurants = Restaurant::query()
            ->where('status', 'active')
            ->whereHas('favorites', fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderBy('id')
            ->cursorPaginate($perPage);

        return response()->json([
            'success' => true,
            'data' => RestaurantResource::collection($restaurants->items())->resolve(),
            'meta' => [
                'per_page' => $restaurants->perPage(),
                'next_cursor' => optional($restaurants->nextCursor())->encode(),
                'prev_cursor' => optional($restaurants->previousCursor())->encode(),
            ],
        ]);
    }

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'restaurant_id' => $restaurant->id,
        ]);

        return response()->json(['success' => true, 'data' => null], 201);
    }

    /**
     * 取消收藏對本來就沒收藏的餐廳視為成功（idempotent），不回 404——
     * 避免使用端重複點擊或狀態競態時被當成錯誤處理。
     */
    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        Favorite::where('user_id', $request->user()->id)
            ->where('restaurant_id', $restaurant->id)
            ->delete();

        return response()->json(['success' => true, 'data' => null]);
    }
}
