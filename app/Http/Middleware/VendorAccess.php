<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Vendor dashboard guard: a signed-in user who owns a store. The store row is
 * attached to the request ('vendor'). Suspended stores only see a notice.
 */
final class VendorAccess implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $auth = app()->auth();
        if (!$auth->check()) {
            return (new Authenticate())->handle($request, $next);
        }
        $vendor = app()->db()->first('SELECT * FROM vendors WHERE user_id = ?', [$auth->id()]);
        if ($vendor === null || !$auth->hasRole('vendor', 'admin')) {
            throw new HttpException(404);
        }
        if (in_array($vendor['status'], ['suspended', 'disabled'], true)) {
            return Response::html(app()->view()->render('vendor/blocked', ['vendor' => $vendor, 'tab' => '']), 403);
        }
        $request->setAttribute('vendor', $vendor);
        return $next($request);
    }
}
