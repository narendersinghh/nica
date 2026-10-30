<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Usage: ->middleware('throttle:api') with limits from config/security.php rate_limits. */
final class Throttle implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $name = $args[0] ?? 'api';
        [$max, $decay] = config("security.rate_limits.{$name}", [60, 60]);
        $key = "throttle:{$name}:" . $request->ip();

        $limiter = app()->limiter();
        $hits = $limiter->hit($key, (int) $decay);
        if ($hits > (int) $max) {
            throw new HttpException(429, '', ['Retry-After' => (string) $limiter->availableIn($key)]);
        }

        $response = $next($request);
        $response->header('X-RateLimit-Limit', (string) $max)
            ->header('X-RateLimit-Remaining', (string) max(0, (int) $max - $hits));
        return $response;
    }
}
