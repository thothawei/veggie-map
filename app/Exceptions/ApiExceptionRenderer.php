<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * 統一 API 錯誤回應格式（見 docs/api.md）：
 * { "success": false, "error": { "code": ..., "message": ... } }
 * 非 /api/* 的請求回傳 null，交還給 Laravel 預設 handler 處理。
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $code, $message] = match (true) {
            $e instanceof ValidationException => [422, 'VALIDATION_ERROR', $e->getMessage()],
            $e instanceof ModelNotFoundException => [404, 'NOT_FOUND', 'Resource not found'],
            $e instanceof NotFoundHttpException => [404, 'NOT_FOUND', 'Resource not found'],
            $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', 'Authentication required'],
            $e instanceof AuthorizationException => [403, 'FORBIDDEN', 'This action is unauthorized'],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'HTTP_ERROR', $e->getMessage() ?: 'Request failed'],
            default => [500, 'SERVER_ERROR', config('app.debug') ? $e->getMessage() : 'Internal server error'],
        };

        $error = ['code' => $code, 'message' => $message];

        if ($e instanceof ValidationException) {
            $error['fields'] = $e->errors();
        }

        return response()->json(['success' => false, 'error' => $error], $status);
    }
}
