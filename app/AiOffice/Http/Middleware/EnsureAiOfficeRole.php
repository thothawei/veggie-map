<?php

namespace App\AiOffice\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI Office 路由的角色守門（規格第 52／53 節）。
 *
 * 用法：
 *   ->middleware('ai-office')                     只要是 AI Office 角色都放行
 *   ->middleware('ai-office:admin,manager')       指定角色
 *
 * 認證本身仍由 auth:sanctum 負責，這裡只管授權。丟 AuthorizationException 而不是
 * abort(403)，是為了走既有的 ApiExceptionRenderer，回應格式跟其他端點一致
 * （{"success":false,"error":{"code":"FORBIDDEN"}}）。
 */
class EnsureAiOfficeRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthorizationException;
        }

        // admin 一律放行（規格第 53 節：Admin = everything），不用在每條路由列舉它。
        if ($user->isAdmin()) {
            return $next($request);
        }

        $allowed = $roles === [] ? $user->canAccessAiOffice() : $user->hasAnyRole($roles);

        if (! $allowed) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
