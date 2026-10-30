<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\App;
use App\Core\ErrorHandler;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\PageCacheMiddleware;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\ReferralCapture;
use App\Http\Middleware\RememberLogin;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\Throttle;
use App\Http\Middleware\VendorAccess;
use App\Http\Middleware\TrustedHost;
use App\Http\Middleware\VerifyCsrfToken;
use App\Services\Cache\PageCache;
use App\Services\Redirects;

final class Kernel
{
    /** Runs on every request, outermost first. */
    private const GLOBAL = [
        TrustedHost::class,
        RememberLogin::class,
        VerifyCsrfToken::class,
        ReferralCapture::class,
    ];

    /** Short names usable in route definitions. */
    private const ALIASES = [
        'auth' => Authenticate::class,
        'guest' => RedirectIfAuthenticated::class,
        'role' => RequireRole::class,
        'throttle' => Throttle::class,
        'vendor' => VendorAccess::class,
        'admin' => AdminAccess::class,
        'cache' => PageCacheMiddleware::class,
    ];

    public function __construct(private App $app)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->pipeline(
                array_map(static fn (string $class) => [$class, []], self::GLOBAL),
                fn (Request $r) => $this->dispatch($r),
            )($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        } catch (\Throwable $e) {
            $response = ($e instanceof HttpException && $e->status() === 404 ? Redirects::resolve($request) : null)
                ?? ErrorHandler::render($this->app, $e);
            if ($e instanceof HttpException) {
                foreach ($e->headers() as $name => $value) {
                    $response->header($name, $value);
                }
            }
        }

        // Admin and vendor changes (products, stores, settings, content) refresh the public page cache.
        if (!$request->isMethod('GET', 'HEAD') && $response->status() < 400 && preg_match('#^/?(admin|dashboard)(/|$)#', $request->path())) {
            PageCache::flush();
        }

        $this->app->session()->save();
        return SecurityHeaders::apply($this->app, $response);
    }

    private function dispatch(Request $request): Response
    {
        $raw = $request->path();

        // Collapse duplicate slashes (/shop//page -> /shop/page).
        if (str_contains($raw, '//') && $request->isMethod('GET', 'HEAD')) {
            return $this->canonicalRedirect($request, (string) preg_replace('#/+#', '/', $raw));
        }

        [$route, $params] = $this->app->router()->resolve($request->method(), $raw);

        // WordPress-style canonical trailing slash, so every legacy URL keeps a single form.
        if ($request->isMethod('GET', 'HEAD') && $raw !== '/') {
            $hasSlash = str_ends_with($raw, '/');
            if ($route->trailingSlash && !$hasSlash) {
                return $this->canonicalRedirect($request, $raw . '/');
            }
            if (!$route->trailingSlash && $hasSlash) {
                return $this->canonicalRedirect($request, rtrim($raw, '/'));
            }
        }

        $request->setAttribute('route', $route);
        $request->setAttribute('route_params', $params);

        $stack = [];
        foreach ($route->middleware as $definition) {
            [$alias, $args] = array_pad(explode(':', $definition, 2), 2, '');
            $class = self::ALIASES[$alias] ?? $alias;
            $stack[] = [$class, $args === '' ? [] : explode(',', $args)];
        }

        return $this->pipeline($stack, fn (Request $r) => $this->callHandler($route->handler, $r))($request);
    }

    private function callHandler(mixed $handler, Request $request): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            [$class, $method] = $handler;
            $result = (new $class($this->app))->{$method}($request);
        } elseif (is_callable($handler)) {
            $result = $handler($request);
        } else {
            throw new \LogicException('Invalid route handler.');
        }

        return match (true) {
            $result instanceof Response => $result,
            is_array($result) => Response::json($result),
            default => Response::html((string) $result),
        };
    }

    /** @param array<int,array{0:class-string<Middleware>,1:string[]}> $stack */
    private function pipeline(array $stack, \Closure $core): \Closure
    {
        $next = $core;
        foreach (array_reverse($stack) as [$class, $args]) {
            $next = static function (Request $request) use ($class, $args, $next): Response {
                /** @var Middleware $middleware */
                $middleware = new $class();
                return $middleware->handle($request, $next, $args);
            };
        }
        return $next;
    }

    private function canonicalRedirect(Request $request, string $path): Response
    {
        $qs = $request->queryString();
        return Response::redirect($this->app->url()->to($path) . ($qs !== '' ? '?' . $qs : ''), 301);
    }
}
