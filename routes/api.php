<?php

use App\AiOffice\Http\Controllers\ActivityController as AiOfficeActivityController;
use App\AiOffice\Http\Controllers\AgentController as AiOfficeAgentController;
use App\AiOffice\Http\Controllers\ApprovalController as AiOfficeApprovalController;
use App\AiOffice\Http\Controllers\DashboardController as AiOfficeDashboardController;
use App\AiOffice\Http\Controllers\HealthController as AiOfficeHealthController;
use App\AiOffice\Http\Controllers\MessageController as AiOfficeMessageController;
use App\AiOffice\Http\Controllers\ProjectController as AiOfficeProjectController;
use App\AiOffice\Http\Controllers\TaskController as AiOfficeTaskController;
use App\AiOffice\Http\Controllers\TaskDependencyController as AiOfficeTaskDependencyController;
use App\AiOffice\Http\Controllers\UsageController as AiOfficeUsageController;
use App\Http\Controllers\Api\Admin\DuplicateRestaurantController as AdminDuplicateRestaurantController;
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
    // 必須排在 /restaurants/{restaurant} 前面，否則會被當成一家 id=suggest 的餐廳。
    // 自動完成另外掛一個比較寬的限流（見 AppServiceProvider）——`throttle:api` 的
    // 60/分鐘會讓正常打字幾輪就撞 429。兩個 middleware 疊著，兩邊都要過。
    Route::get('/restaurants/suggest', [RestaurantController::class, 'suggest'])
        ->middleware('throttle:suggest')
        ->withoutMiddleware('throttle:api');
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

            // 規格第 38／50 節：今日統計。在這之前前端是自己從分頁清單數出來的，
            // 數字會隨著「載入了幾頁」變動。
            Route::get('/dashboard', [AiOfficeDashboardController::class, 'show']);

            Route::apiResource('projects', AiOfficeProjectController::class);

            Route::get('/projects/{project}/tasks', [AiOfficeTaskController::class, 'index']);
            Route::post('/projects/{project}/tasks', [AiOfficeTaskController::class, 'store']);

            Route::get('/tasks/{task}', [AiOfficeTaskController::class, 'show']);
            Route::patch('/tasks/{task}', [AiOfficeTaskController::class, 'update']);

            // 規格第 50 節。PATCH 也改得動狀態，但這兩支有明確語意：retry 只收
            // 失敗／取消並繞過 max_retries，cancel 對 running 是協作式取消。
            Route::post('/tasks/{task}/retry', [AiOfficeTaskController::class, 'retry']);
            Route::post('/tasks/{task}/cancel', [AiOfficeTaskController::class, 'cancel']);

            // 只有這條路徑會產生循環相依（新任務沒有下游），環的偵測守在這裡。
            Route::post('/tasks/{task}/dependencies', [AiOfficeTaskDependencyController::class, 'store']);
            Route::delete(
                '/tasks/{task}/dependencies/{dependency}',
                [AiOfficeTaskDependencyController::class, 'destroy'],
            );

            // Agent 唯讀：開放 API 建立 Agent 等於開放任意設定 system prompt 與權限。
            Route::get('/agents', [AiOfficeAgentController::class, 'index']);
            Route::get('/agents/{agent}', [AiOfficeAgentController::class, 'show']);
            Route::get('/agents/{agent}/memories', [AiOfficeAgentController::class, 'memories']);

            // 用量／成本／效能（規格第 38、40 節）。唯讀，viewer 也看得到。
            Route::get('/usage', [AiOfficeUsageController::class, 'index']);
            Route::get('/stats/agents', [AiOfficeUsageController::class, 'agents']);

            // 事件流（規格第 35／36 節）。SSE 本身在 auth:sanctum 群組外面另外掛，
            // 因為 EventSource 帶不了 Authorization 標頭，改用這裡發的一次性票。
            Route::get('/projects/{project}/activities', [AiOfficeActivityController::class, 'index']);

            // 規格第 34 節：Agent 之間的往來訊息。唯讀——開放寫入等於讓人偽造
            // Agent 的發言，這條時間軸就失去它唯一的價值。
            Route::get('/projects/{project}/messages', [AiOfficeMessageController::class, 'index']);
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

            // 重複標記的審核（第二十二節）。同步只標記，這裡是唯一的處置出口。
            Route::get('/duplicates', [AdminDuplicateRestaurantController::class, 'index']);
            Route::post(
                '/restaurants/{restaurant}/duplicate',
                [AdminDuplicateRestaurantController::class, 'resolve'],
            );
            Route::get('/verification-types', [AdminRestaurantVerificationController::class, 'types']);
            Route::post('/restaurants/{restaurant}/verifications', [AdminRestaurantVerificationController::class, 'store']);
        });
    });

    // SSE 串流：靠 ticket 認證，所以不在 auth:sanctum 群組內；角色檢查在
    // Controller 裡用兌換出來的使用者再做一次，不是少一道關卡。
    Route::get('/ai-office/projects/{project}/events', [AiOfficeActivityController::class, 'stream']);
});
