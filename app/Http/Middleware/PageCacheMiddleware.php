<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Cache\PageCache;

/**
 * Route middleware 'cache': serves public pages from the page cache to
 * visitors without any cookie of ours (no session, cart or login). Anything
 * personal, any query string, and anything that sets a cookie is never cached.
 */
final class PageCacheMiddleware implements Middleware
{
    private const PERSONAL_COOKIES = [\App\Services\Cart\Cart::COOKIE, \App\Core\Security\Auth::REMEMBER_COOKIE];

    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        $app = app();
        if (!$this->eligible($request)) {
            return $next($request);
        }
        $key = PageCache::key($app->url()->host(), '/' . ltrim($request->path(), '/'));
        if ($entry = PageCache::get($key)) {
            // Inline scripts were rendered with another request's nonce: swap in this one.
            $html = str_replace('nonce="' . $entry['nonce'] . '"', 'nonce="' . $app->cspNonce() . '"', $entry['html']);
            $response = Response::html($html);
            foreach ($entry['headers'] as $name => $value) {
                $response->header($name, $value);
            }
            return $response->header('X-Page-Cache', 'HIT')->header('Cache-Control', 'public, max-age=0, must-revalidate');
        }

        $app->renderingForCache(true);
        try {
            $response = $next($request);
        } finally {
            $app->renderingForCache(false);
        }
        if ($response->status() === 200 && !$app->session()->isStarted() && !$response->hasCookies()
            && str_starts_with((string) ($response->getHeader('Content-Type') ?? 'text/html'), 'text/html')) {
            $keep = array_filter(['Content-Type' => $response->getHeader('Content-Type'), 'Link' => $response->getHeader('Link')]);
            PageCache::put($key, $response->body(), $app->cspNonce(), $keep);
            $response->header('X-Page-Cache', 'MISS');
        }
        return $response;
    }

    private function eligible(Request $request): bool
    {
        if (!PageCache::enabled() || !$request->isMethod('GET') || $request->queryString() !== '' || app()->session()->hasCookie()) {
            return false;
        }
        foreach (self::PERSONAL_COOKIES as $cookie) {
            if ($request->cookie($cookie) !== null) {
                return false;
            }
        }
        return true;
    }
}
