<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Auth;

/** Restores a login from the remember-me cookie when there is no logged-in session. */
final class RememberLogin implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $cookie = (string) $request->cookie(Auth::REMEMBER_COOKIE, '');
        if ($cookie === '' || app()->auth()->check()) {
            return $next($request);
        }
        $auth = app()->auth();
        $renewed = $auth->loginFromRememberCookie($cookie, $request->ip(), $request->userAgent());
        $response = $next($request);
        [$name, $value, $expires] = $renewed ?? [Auth::REMEMBER_COOKIE, '', time() - 3600];
        return $response->cookie($name, $value, ['expires' => $expires]);
    }
}
