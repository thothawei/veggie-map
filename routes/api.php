<?php

use App\AiOffice\Http\Controllers\ActivityController as AiOfficeActivityController;
use App\AiOffice\Http\Controllers\AgentController as AiOfficeAgentController;
use App\AiOffice\Http\Controllers\ApprovalController as AiOfficeApprovalController;
use App\AiOffice\Http\Controllers\HealthController as AiOfficeHealthController;
use App\AiOffice\Http\Controllers\ProjectController as AiOfficeProjectController;
use App\AiOffice\Http\Controllers\TaskController as AiOfficeTaskController;
use App\AiOffice\Http\Controllers\TaskDependencyController as AiOfficeTaskDependencyController;
use App\Http\Controllers\Api\Admin\MenuItemController as AdminMenuItemController;
use App\Http\Controllers\Api\Admin\RestaurantReportController as AdminRestaurantReportController;
use App\Http\Controllers\Api\Admin\RestaurantVerificationController as AdminRestaurantVerificationController;
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

        // AI Office 子系統（見 docs/implementation-plan.md）。整段掛 `ai-office` 中介層，
        // 只有 admin／manager／developer／viewer 進得來——一般消費者角色 `user`
        // 註冊過也看不到，這是預設拒絕不是事後補檢查。
        Route::prefix('ai-office')->middleware('ai-office')->group(function () {
            Route::get('/health', [AiOfficeHealthController::class, 'show']);

            Route::apiResource('projects', AiOfficeProjectController::class);

            Route::get('/projects/{project}/tasks', [AiOfficeTaskController::class, 'index']);
            Route::post('/projects/{project}/tasks', [AiOfficeTaskController::class, 'store']);

            Route::get('/tasks/{task}', [AiOfficeTaskController::class, 'show']);
            Route::patch('/tasks/{task}', [AiOfficeTaskController::class, 'update']);

            // 只有這條路徑會產生循環相依（新任務沒有下游），環的偵測守在這裡。
            Route::post('/tasks/{task}/dependencies', [AiOfficeTaskDependencyController::class, 'store']);
            Route::delete(
                '/tasks/{task}/dependencies/{dependency}',
                [AiOfficeTaskDependencyController::class, 'destroy'],
            );

            // Agent 唯讀：開放 API 建立 Agent 等於開放任意設定 system prompt 與權限。
            Route::get('/agents', [AiOfficeAgentController::class, 'index']);
            Route::get('/agents/{agent}', [AiOfficeAgentController::class, 'show']);

            // 事件流（規格第 35／36 節）。SSE 本身在 auth:sanctum 群組外面另外掛，
            // 因為 EventSource 帶不了 Authorization 標頭，改用這裡發的一次性票。
            Route::get('/projects/{project}/activities', [AiOfficeActivityController::class, 'index']);
            Route::post('/projects/{project}/events/ticket', [AiOfficeActivityController::class, 'ticket']);

            Route::get('/approvals', [AiOfficeApprovalController::class, 'index']);
            Route::get('/approvals/{approval}', [AiOfficeApprovalController::class, 'show']);
            Route::post('/approvals/{approval}/approve', [AiOfficeApprovalController::class, 'approve']);
            Route::post('/approvals/{approval}/reject', [AiOfficeApprovalController::class, 'reject']);
        });

        Route::prefix('admin')->group(function () {
            Route::get('/reports', [AdminRestaurantReportController::class, 'index']);
            Route::post('/reports/{report}/approve', [AdminRestaurantReportController::class, 'approve']);
            Route::post('/reports/{report}/reject', [AdminRestaurantReportController::class, 'reject']);

            Route::get('/reviews', [AdminReviewController::class, 'index']);
            Route::post('/reviews/{review}/hide', [AdminReviewController::class, 'hide']);

            Route::post('/restaurants/{restaurant}/menu-items', [AdminMenuItemController::class, 'store']);
            Route::get('/verification-types', [AdminRestaurantVerificationController::class, 'types']);
            Route::post('/restaurants/{restaurant}/verifications', [AdminRestaurantVerificationController::class, 'store']);
        });
    });

    // SSE 串流：靠 ticket 認證，所以不在 auth:sanctum 群組內；角色檢查在
    // Controller 裡用兌換出來的使用者再做一次，不是少一道關卡。
    Route::get('/ai-office/projects/{project}/events', [AiOfficeActivityController::class, 'stream']);
});
