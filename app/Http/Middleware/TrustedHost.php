<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Rejects requests whose Host header is not in TRUSTED_HOSTS (400). */
final class TrustedHost implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        app()->url()->host(); // throws HttpException(400) when untrusted
        return $next($request);
    }
}
