<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveClosureSignalRequest;
use App\Models\Restaurant;
use App\Models\RestaurantClosureSignal;
use App\Services\RestaurantCacheInvalidator;
use Illuminate\Http\JsonResponse;

/**
 * 疑似歇業的審核。
 *
 * 偵測端（restaurants:check-closed）只寫訊號、不下架——OSM 節點消失可能只是
 * 被合併進 way 或誤刪。這裡是那些訊號唯一的出口：confirm 才真的下架。
 */
class ClosureSignalController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('reviewDuplicates', Restaurant::class);

        $signals = RestaurantClosureSignal::query()
            ->whereNull('resolution')
            // 餐廳可能已經因為別的原因被下架，用 withoutGlobalScopes 一併撈出來，
            // 否則那筆訊號會永遠留在待審清單裡、卻看不到是哪一家。
            ->with(['restaurant' => fn ($query) => $query->select([
                'id', 'name', 'slug', 'address', 'city', 'latitude', 'longitude', 'status', 'source_id', 'website',
            ])])
            ->orderBy('detected_at')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $signals->map(fn (RestaurantClosureSignal $signal) => [
                'id' => $signal->id,
                'signal' => $signal->signal,
                'metadata' => $signal->metadata,
                'detected_at' => $signal->detected_at?->toIso8601String(),
                'restaurant' => $signal->restaurant === null ? null : [
                    'id' => $signal->restaurant->id,
                    'name' => $signal->restaurant->name,
                    'slug' => $signal->restaurant->slug,
                    'address' => $signal->restaurant->address,
                    'city' => $signal->restaurant->city,
                    'status' => $signal->restaurant->status,
                    'website' => $signal->restaurant->website,
                    // Admin 要判斷「這家店還在不在」，最快的方式就是自己去 Google
                    // 地圖看一眼。把連結直接給出來，不要逼他複製座標再貼上。
                    'google_maps_url' => sprintf(
                        'https://www.google.com/maps/search/?api=1&query=%s,%s',
                        $signal->restaurant->latitude,
                        $signal->restaurant->longitude,
                    ),
                ],
            ])->all(),
        ]);
    }

    public function resolve(
        ResolveClosureSignalRequest $request,
        RestaurantClosureSignal $signal,
    ): JsonResponse {
        $this->authorize('reviewDuplicates', Restaurant::class);

        $resolution = $request->validated()['resolution'];

        $signal->update([
            'resolution' => $resolution,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($resolution === 'confirmed' && $signal->restaurant !== null) {
            // 下架而不是刪除，跟回報核准、重複審核一致。
            $signal->restaurant->update(['status' => 'inactive']);
            RestaurantCacheInvalidator::invalidate(
                $signal->restaurant->id,
                $signal->restaurant->slug,
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $signal->id,
                'resolution' => $signal->resolution,
                'restaurant_status' => $signal->restaurant?->fresh()->status,
            ],
        ]);
    }
}
