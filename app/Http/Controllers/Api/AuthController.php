<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            // 顯式帶入，不依賴 migration 的 DB-side default——create() 後的記憶體
            // model 不會自動回填 DB default，少了這行 response 裡 role 會是 null。
            'role' => 'user',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                ...$this->issueToken($user),
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                ...$this->issueToken($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'data' => null]);
    }

    /**
     * 換一張新 token（沿用同一支 `config('sanctum.expiration')` 上限）。
     *
     * 前端在**還沒過期前**主動呼叫這支，讓活躍使用者不會因為全域過期上限被
     * 硬性登出——refresh 之後舊 token 立刻失效，不是「兩張都能用一陣子」，
     * 否則舊 token 外流時撤銷會失去意義。
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data' => $this->issueToken($user),
        ]);
    }

    /**
     * @return array{token: string, expires_at: string|null}
     */
    private function issueToken(User $user): array
    {
        $expiresAt = $this->tokenExpiresAt();

        return [
            'token' => $user->createToken('api', ['*'], $expiresAt)->plainTextToken,
            // 這裡回的時間點是給前端排程 refresh 用的參考值——真正生效的過期
            // 判斷仍然是 Sanctum Guard 比對 token 的 created_at（見
            // config/sanctum.php 的說明），這個欄位只是讓前端不用自己重算一次。
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    private function tokenExpiresAt(): ?Carbon
    {
        $minutes = config('sanctum.expiration');

        return $minutes ? now()->addMinutes((int) $minutes) : null;
    }
}
