<?php

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

Route::get('/restaurants', [RestaurantController::class, 'index']);
Route::get('/restaurants/{restaurant}', [RestaurantController::class, 'show']);
Route::get('/restaurants/{restaurant}/reviews', [ReviewController::class, 'index']);

Route::get('/diets', [LookupController::class, 'dietTypes']);
Route::get('/features', [LookupController::class, 'features']);

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
    });
});
