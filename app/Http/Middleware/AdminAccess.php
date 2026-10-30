<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * /admin/ guard: admin or staff role. Admins must have two-factor
 * authentication on outside local development. Usage: 'admin' or 'admin:finance'
 * (an ability staff members do not have, see AdminAccess::STAFF).
 */
final class AdminAccess implements Middleware
{
    /** What staff (support/fulfilment) may do. Admins can do everything. */
    public const STAFF = ['dashboard', 'orders', 'products', 'reviews', 'customers', 'stylists', 'vendors.view'];

    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $app = app();
        $auth = $app->auth();
        if (!$auth->check()) {
            return (new Authenticate())->handle($request, $next);
        }
        if (!$auth->hasRole('admin', 'staff') || $auth->impersonatorId() !== null) {
            throw new HttpException(404);
        }
        if ($auth->hasRole('admin') && !$app->isLocal() && empty($auth->user()['two_factor_enabled_at'])) {
            $app->session()->flash('account_notice', 'Turn on two-factor authentication to use the admin panel.');
            return Response::redirect(link_to('/my-account/security/'), 302);
        }
        foreach ($args as $ability) {
            if (!self::can($ability)) {
                throw new HttpException(403);
            }
        }
        return $next($request);
    }

    public static function can(string $ability): bool
    {
        $auth = app()->auth();
        return $auth->hasRole('admin') || ($auth->hasRole('staff') && in_array($ability, self::STAFF, true));
    }
}
