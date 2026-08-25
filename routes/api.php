<?php

use App\Http\Controllers\Api\Admin\MenuItemController as AdminMenuItemController;
use App\Http\Controllers\Api\Admin\RestaurantReportController as AdminRestaurantReportController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\RestaurantReportController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

// 見總體規劃第十六節：/api/v1/restaurants（跟其他公開端點）用 Redis-based rate limiter，
// 限流規則見 AppServiceProvider::boot() 的 RateLimiter::for('api', ...)。整個 API 都套用，
// 不是只有 /restaurants——匿名可存取的端點都有被打爆的風險，不是只有文件明講的那一條。
Route::middleware('throttle:api')->group(function () {
    Route::get('/restaurants', [RestaurantController::class, 'index']);
    Route::get('/restaurants/recommended', [RestaurantController::class, 'recommended']);
    Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show']);
    Route::get('/restaurants/{restaurant}/reviews', [ReviewController::class, 'index']);

    Route::get('/diets', [LookupController::class, 'dietTypes']);
    Route::get('/features', [LookupController::class, 'features']);
    Route::get('/cities', [LookupController::class, 'cities']);

    Route::get('/geocode', [GeocodeController::class, 'search']);

    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/me', [MeController::class, 'show']);
        Route::get('/me/favorites', [FavoriteController::class, 'index']);

        Route::post('/restaurants/{restaurant}/favorite', [FavoriteController::class, 'store']);
        Route::delete('/restaurants/{restaurant}/favorite', [FavoriteController::class, 'destroy']);

        Route::post('/restaurants/{restaurant}/reviews', [ReviewController::class, 'store']);
        Route::post('/restaurants/{restaurant}/reports', [RestaurantReportController::class, 'store']);

        Route::prefix('admin')->group(function () {
            Route::get('/reports', [AdminRestaurantReportController::class, 'index']);
            Route::post('/reports/{report}/approve', [AdminRestaurantReportController::class, 'approve']);
            Route::post('/reports/{report}/reject', [AdminRestaurantReportController::class, 'reject']);

            Route::get('/reviews', [AdminReviewController::class, 'index']);
            Route::post('/reviews/{review}/hide', [AdminReviewController::class, 'hide']);

            Route::post('/restaurants/{restaurant}/menu-items', [AdminMenuItemController::class, 'store']);
        });
    });
});
