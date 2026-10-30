<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** The setup wizard exists only until setup is finished; afterwards it is a plain 404. */
final class SetupOnly implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        if (app()->isInstalled()) {
            throw new HttpException(404);
        }
        return $next($request);
    }
}
