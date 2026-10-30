<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Router
{
    /** @var Route[] */
    private array $routes = [];
    /** @var array<string,Route> */
    private array $named = [];
    private array $groupStack = [];

    public function get(string $pattern, mixed $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): Route
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->add(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    public function match(array $methods, string $pattern, mixed $handler): Route
    {
        return $this->add(array_map('strtoupper', $methods), $pattern, $handler);
    }

    /**
     * @param array{prefix?:string, middleware?:string[], trailing_slash?:bool} $options
     */
    public function group(array $options, callable $routes): void
    {
        $this->groupStack[] = $options;
        $routes($this);
        array_pop($this->groupStack);
    }

    public function add(array $methods, string $pattern, mixed $handler): Route
    {
        $prefix = '';
        $middleware = [];
        $trailing = true;
        foreach ($this->groupStack as $group) {
            $prefix .= '/' . trim((string) ($group['prefix'] ?? ''), '/');
            $middleware = array_merge($middleware, (array) ($group['middleware'] ?? []));
            if (array_key_exists('trailing_slash', $group)) {
                $trailing = (bool) $group['trailing_slash'];
            }
        }
        $full = '/' . trim(preg_replace('#/+#', '/', $prefix . '/' . $pattern), '/');

        $route = new Route($methods, $full, $handler);
        $route->middleware(...$middleware);
        if (!$trailing || preg_match('/\.[a-z0-9]+$/i', $full)) {
            $route->withoutTrailingSlash();
        }
        $this->routes[] = $route;
        return $route;
    }

    /**
     * @return array{0:Route,1:array<string,string>}
     * @throws HttpException 404 / 405
     */
    public function resolve(string $method, string $path): array
    {
        $lookup = $path === '/' ? '/' : rtrim($path, '/');
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $route->match($lookup);
            if ($params === null) {
                continue;
            }
            if (in_array($method, $route->methods, true)) {
                return [$route, $params];
            }
            $allowed = array_merge($allowed, $route->methods);
        }
        if ($allowed !== []) {
            throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404);
    }

    public function path(string $name, array $params = []): string
    {
        $route = $this->named()[$name] ?? null;
        if ($route === null) {
            throw new \InvalidArgumentException("Unknown route '{$name}'.");
        }
        return $route->build($params);
    }

    /** @return Route[] */
    public function routes(): array
    {
        return $this->routes;
    }

    private function named(): array
    {
        // Rebuilt lazily because ->name() is called after add().
        if (count($this->named) !== count(array_filter($this->routes, static fn (Route $r) => $r->name !== null))) {
            $this->named = [];
            foreach ($this->routes as $route) {
                if ($route->name !== null) {
                    $this->named[$route->name] = $route;
                }
            }
        }
        return $this->named;
    }
}
