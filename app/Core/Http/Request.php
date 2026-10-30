<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    private array $attributes = [];
    private ?string $basePath = null;

    public function __construct(
        private array $query,
        private array $post,
        private array $server,
        private array $cookies,
        private array $files,
        private string $body = '',
    ) {
        $type = strtolower((string) ($this->server['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($type, 'application/json') && $this->body !== '') {
            $decoded = json_decode($this->body, true);
            $this->post = is_array($decoded) ? $decoded : [];
        }
    }

    public static function capture(): self
    {
        $body = '';
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            $body = (string) file_get_contents('php://input', false, null, 0, 2_000_000);
        }
        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES, $body);
    }

    /** Build a request by hand (tests and console). */
    public static function create(string $method, string $uri, array $params = [], array $server = [], array $cookies = []): self
    {
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $method = strtoupper($method);
        $server = array_merge([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $server);
        return $method === 'GET'
            ? new self(array_merge($query, $params), [], $server, $cookies, [])
            : new self($query, $params, $server, $cookies, []);
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            $override = strtoupper((string) ($this->post['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $method;
    }

    public function isMethod(string ...$methods): bool
    {
        return in_array($this->method(), array_map('strtoupper', $methods), true);
    }

    /** Sub-directory the app is installed in ("" when at the domain root). */
    public function basePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '/index.php');
        // Only a real front controller reveals the install folder (PHP's dev server sets
        // SCRIPT_NAME to the requested URL for paths that look like files).
        if (!str_ends_with($script, '.php')) {
            return $this->basePath = '';
        }
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/.');
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        // Only treat it as a base path when the URL really starts with it
        // (a root .htaccess rewriting into /public must not leak "/public" into links).
        return $this->basePath = ($dir !== '' && str_starts_with($uri, $dir . '/')) ? $dir : '';
    }

    /** Decoded path relative to the base path, always starting with "/". */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url('http://x' . $uri, PHP_URL_PATH) ?? '/'));
        $base = $this->basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        // Keep duplicate slashes so the kernel can redirect them to the canonical URL.
        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    public function queryString(): string
    {
        return (string) ($this->server['QUERY_STRING'] ?? parse_url('http://x' . ($this->server['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? '');
    }

    /** Path plus query string. */
    public function uri(): string
    {
        $qs = $this->queryString();
        return $this->path() . ($qs !== '' ? '?' . $qs : '');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** Trimmed string input; never an array. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function only(string ...$keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        $plain = strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$plain]) ? (string) $this->server[$plain] : $default;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept', ''), 'application/json')
            || strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Route parameter. */
    public function param(string $key, ?string $default = null): ?string
    {
        $params = $this->attributes['route_params'] ?? [];
        return isset($params[$key]) ? (string) $params[$key] : $default;
    }
}
