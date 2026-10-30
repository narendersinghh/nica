<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\App;
use App\Core\Http\Response;

/**
 * Applied by the kernel to every response, including error pages.
 * Inline scripts/styles must carry nonce="<?= csp_nonce() ?>".
 */
final class SecurityHeaders
{
    public static function apply(App $app, Response $response): Response
    {
        $nonce = $app->cspNonce();
        $extra = array_merge_recursive((array) config('security.csp', []), \App\Services\Integrations::cspSources());
        $src = static fn (string $directive, array $base) => implode(' ', array_unique(array_merge($base, (array) ($extra[$directive] ?? []))));

        $secure = false;
        try {
            $secure = $app->url()->isSecure();
        } catch (\Throwable) {
            // untrusted host: still send the headers below
        }

        $csp = [
            "default-src 'self'",
            'script-src ' . $src('script-src', ["'self'", "'nonce-{$nonce}'"]),
            'style-src ' . $src('style-src', ["'self'", "'nonce-{$nonce}'"]),
            'img-src ' . $src('img-src', ["'self'"]),
            'font-src ' . $src('font-src', ["'self'"]),
            'media-src ' . $src('media-src', ["'self'"]),
            'connect-src ' . $src('connect-src', ["'self'"]),
            'frame-src ' . $src('frame-src', ["'self'"]),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if ($secure) {
            $csp[] = 'upgrade-insecure-requests';
        }

        $response->header('Content-Security-Policy', implode('; ', $csp))
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self "https://js.stripe.com")')
            ->header('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        if ($secure && $app->isProduction()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (!$app->isProduction()) {
            $response->header('X-Robots-Tag', 'noindex, nofollow'); // staging/local copies stay out of search engines
        }

        // Pages for logged-in users / with forms must never be stored by shared caches.
        if ($response->getHeader('Cache-Control') === null) {
            $response->header('Cache-Control', $app->session()->isStarted() ? 'private, no-store' : 'no-cache');
        }

        if (function_exists('header_remove') && PHP_SAPI !== 'cli') {
            header_remove('X-Powered-By');
        }
        return $response;
    }
}
