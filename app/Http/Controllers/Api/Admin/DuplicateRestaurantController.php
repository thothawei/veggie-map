<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveDuplicateRestaurantRequest;
use App\Models\Restaurant;
use App\Repositories\DuplicateRestaurantRepository;
use App\Services\RestaurantCacheInvalidator;
use Illuminate\Http\JsonResponse;

/**
 * 重複餐廳的審核（總 Prompt 第二十二節）。同步只**標記**、不合併也不刪除，
 * 這裡是那些標記唯一的出口。
 *
 * 沒有做「自動合併」：兩筆同名又相近，也可能是同一條街上的兩家分店；合併會把
 * 一家真實存在的店從地圖上抹掉，而且不可逆。Admin 能做的是「這筆留著」與
 * 「這筆下架」，後者只改 status，資料仍在。
 */
class DuplicateRestaurantController extends Controller
{
    public function index(DuplicateRestaurantRepository $duplicates): JsonResponse
    {
        $this->authorize('reviewDuplicates', Restaurant::class);

        return response()->json([
            'success' => true,
            'data' => $duplicates->groups(),
        ]);
    }

    /**
     * 刻意用 id 自己查而不是 route model binding：`Restaurant::resolveRouteBinding()`
     * 只認 `status = active`，而這個清單本來就會包含已經被下架的重複筆——用預設
     * binding 的話，要清掉一筆已下架餐廳的過期標記會直接 404。
     */
    public function resolve(
        ResolveDuplicateRestaurantRequest $request,
        int $restaurant,
    ): JsonResponse {
        $this->authorize('reviewDuplicates', Restaurant::class);

        $restaurant = Restaurant::findOrFail($restaurant);

        $action = $request->validated()['action'];

        $restaurant->is_possible_duplicate = false;

        if ($action === 'deactivate') {
            // 下架而不是刪除：判斷錯了還救得回來，而且 reviews／favorites 的外鍵
            // 也不會跟著消失。
            $restaurant->status = 'inactive';
        }

        $restaurant->save();

        // status 變了會影響列表與詳情，而 detail cache 的 key 是 id——不清的話
        // 已下架的店還會被吐 600 秒。
        RestaurantCacheInvalidator::invalidate($restaurant->id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $restaurant->id,
                'status' => $restaurant->status,
                'is_possible_duplicate' => $restaurant->is_possible_duplicate,
            ],
        ]);
    }
}
