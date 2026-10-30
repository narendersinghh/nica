<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class RedirectIfAuthenticated implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        return app()->auth()->check() ? Response::redirect('/my-account/') : $next($request);
    }
}
