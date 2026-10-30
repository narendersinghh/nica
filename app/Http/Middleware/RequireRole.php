<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Usage: ->middleware('auth', 'role:admin,staff') */
final class RequireRole implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $auth = app()->auth();
        if (!$auth->check()) {
            return (new Authenticate())->handle($request, $next);
        }
        if ($args !== [] && !$auth->hasRole(...$args)) {
            // 404 rather than 403 so the existence of admin areas is not advertised.
            throw new HttpException(404);
        }
        return $next($request);
    }
}
