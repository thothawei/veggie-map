<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRestaurantVerificationRequest;
use App\Models\Restaurant;
use App\Models\RestaurantVerification;
use App\Services\VerificationService;
use App\Support\VerificationCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * 第十一節的可信度寫入路徑：在這之前只有 OSM 同步的 external_source 會寫
 * restaurant_verifications，其他類型的分數永遠是 0。合法類型來自
 * config/vegetarian.php 的 admin_verifiable_types，分數來自同一份的
 * verification_weights，Controller 不決定分數。
 */
class RestaurantVerificationController extends Controller
{
    /**
     * 前端下拉用的可寫類型清單（code／label／分數），跟寫入端吃同一份 config。
     */
    public function types(): JsonResponse
    {
        $this->authorize('create', RestaurantVerification::class);

        return response()->json([
            'success' => true,
            'data' => VerificationCatalog::adminTypes(),
        ]);
    }

    public function store(
        CreateRestaurantVerificationRequest $request,
        Restaurant $restaurant,
        VerificationService $verifications,
    ): JsonResponse {
        $this->authorize('create', RestaurantVerification::class);

        $data = $request->validated();
        $note = $data['note'] ?? null;

        $verification = $verifications->record(
            $restaurant,
            $data['verification_type'],
            $request->user(),
            $note === null ? null : ['note' => $note],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $verification->id,
                'restaurant_id' => $verification->restaurant_id,
                'verification_type' => $verification->verification_type,
                'score' => $verification->score,
                'verified_by' => $verification->verified_by,
                'verified_at' => $verification->verified_at,
                'expires_at' => $verification->expires_at,
                'metadata' => $verification->metadata,
                'confidence_score' => $restaurant->fresh('confidenceScore')?->confidenceScore?->score,
            ],
        ], 201);
    }
}
