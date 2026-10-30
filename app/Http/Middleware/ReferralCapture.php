<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Referrals\Referrals;
use App\Services\Settings;

/** Any page opened with ?ref=STYLISTCODE remembers that stylist for later orders (last click wins). */
final class ReferralCapture implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && is_string($request->query('ref')) && $request->query('ref') !== '' && $response->status() < 400) {
            $code = (new Referrals(app()->db()))->capture($request);
            if ($code !== null) {
                $response->cookie(Referrals::COOKIE, $code, ['expires' => time() + 86400 * (int) Settings::get('referrals.cookie_days', 30)]);
            }
        }
        return $response;
    }
}
