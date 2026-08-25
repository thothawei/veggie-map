<?php

use App\AiOffice\Http\Middleware\EnsureAiOfficeRole;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\LogSlowApiRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 純 API 專案沒有 `login` 具名路由；預設 Authenticate middleware 在請求沒帶
        // `Accept: application/json` 時會想導去該路由並丟 RouteNotFoundException，
        // 蓋掉原本該回的 401。這裡強制永遠不重導，讓 unauthenticated() 正常拋
        // AuthenticationException，交給 ApiExceptionRenderer 統一處理。
        $middleware->redirectGuestsTo(fn () => null);

        // 每個 API 請求都量 response time（規格第三十五節）。慢的寫 log，
        // 全部都帶 X-Response-Time-Ms 標頭。
        $middleware->api(prepend: [LogSlowApiRequests::class]);

        $middleware->alias([
            'ai-office' => EnsureAiOfficeRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(fn (Throwable $e, $request) => (new ApiExceptionRenderer)($e, $request));
    })->create();
