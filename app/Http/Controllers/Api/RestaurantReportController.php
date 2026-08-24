<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRestaurantReportRequest;
use App\Http\Resources\RestaurantReportResource;
use App\Models\Restaurant;
use App\Models\RestaurantReport;
use Illuminate\Http\JsonResponse;

class RestaurantReportController extends Controller
{
    public function store(CreateRestaurantReportRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorize('create', RestaurantReport::class);

        $report = $restaurant->reports()->create([
            'user_id' => $request->user()->id,
            'type' => $request->validated('type'),
            'description' => $request->validated('description'),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data' => new RestaurantReportResource($report),
        ], 201);
    }
}
