<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class VerifyCsrfToken implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        if ($request->isMethod('GET', 'HEAD', 'OPTIONS') || $this->excluded($request->path())) {
            return $next($request);
        }
        if (!app()->csrf()->verify($request, app()->url()->host())) {
            throw new HttpException(419);
        }
        return $next($request);
    }

    private function excluded(string $path): bool
    {
        foreach ((array) config('security.csrf_except', []) as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }
}
