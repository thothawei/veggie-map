<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use App\Repositories\RestaurantRepository;
use Illuminate\Http\JsonResponse;

class RestaurantController extends Controller
{
    public function __construct(private readonly RestaurantRepository $restaurants) {}

    public function index(SearchRestaurantRequest $request): JsonResponse
    {
        $paginator = $this->restaurants->search($request->validated());

        return response()->json([
            'success' => true,
            'data' => RestaurantResource::collection($paginator->items())->resolve(),
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => optional($paginator->nextCursor())->encode(),
                'prev_cursor' => optional($paginator->previousCursor())->encode(),
            ],
        ]);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === 'active', 404);

        $restaurant->load(['dietTypes', 'features', 'menuItems', 'confidenceScore']);

        return response()->json([
            'success' => true,
            'data' => new RestaurantResource($restaurant),
        ]);
    }
}
