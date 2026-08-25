<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

class MenuItemController extends Controller
{
    public function store(CreateMenuItemRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorize('create', MenuItem::class);

        $data = $request->validated();
        $item = $restaurant->menuItems()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'diet_type' => $data['diet_type'],
            'is_available' => $request->boolean('is_available', true),
        ]);

        return response()->json([
            'success' => true,
            'data' => new MenuItemResource($item),
        ], 201);
    }
}
