<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Request;

/**
 * Builds absolute URLs from the incoming request. The domain is never
 * hard-coded: it is the request's Host header, accepted only when it matches
 * TRUSTED_HOSTS (prevents Host-header injection into links, emails and resets).
 */
final class Url
{
    private ?string $base = null;

    public function __construct(
        private Request $request,
        private array $trustedHosts,
        private array $trustedProxies,
        private string $forceScheme,
        private bool $allowAnyHost,
        private string $publicDir,
    ) {
    }

    public function host(): string
    {
        $raw = strtolower((string) $this->request->server('HTTP_HOST', $this->request->server('SERVER_NAME', 'localhost')));
        // host[:port] or [ipv6][:port]
        if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::(\d{1,5}))?$/', $raw, $m)) {
            throw new HttpException(400, 'Invalid host header.');
        }
        $name = $m[1];
        if (!$this->isTrusted($name)) {
            throw new HttpException(400, 'Untrusted host.');
        }
        return $raw;
    }

    public function hostName(): string
    {
        return (string) preg_replace('/:\d+$/', '', $this->host());
    }

    public function isTrusted(string $name): bool
    {
        if ($this->trustedHosts === []) {
            return $this->allowAnyHost;
        }
        foreach ($this->trustedHosts as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $name) {
                return true;
            }
            if (str_starts_with($pattern, '*.') && str_ends_with($name, substr($pattern, 1))) {
                return true;
            }
        }
        return false;
    }

    public function scheme(): string
    {
        if ($this->forceScheme === 'http' || $this->forceScheme === 'https') {
            return $this->forceScheme;
        }
        if ($this->fromTrustedProxy()) {
            $proto = strtolower(trim(explode(',', (string) $this->request->server('HTTP_X_FORWARDED_PROTO', ''))[0]));
            if ($proto === 'https' || $proto === 'http') {
                return $proto;
            }
        }
        $https = strtolower((string) $this->request->server('HTTPS', ''));
        if ($https !== '' && $https !== 'off') {
            return 'https';
        }
        return (string) $this->request->server('SERVER_PORT') === '443' ? 'https' : 'http';
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    /** scheme://host[:port][/base-path] with no trailing slash. */
    public function base(): string
    {
        return $this->base ??= $this->scheme() . '://' . $this->host() . $this->request->basePath();
    }

    public function to(string $path = '/', array $query = []): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $url = $this->base() . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    /** Asset URL with a content-based version for long-term immutable caching. */
    public function asset(string $path): string
    {
        $path = AssetMinifier::resolve($this->publicDir, '/' . ltrim($path, '/'));
        $file = $this->publicDir . $path;
        $version = is_file($file) ? substr(md5((string) filemtime($file) . filesize($file)), 0, 10) : null;
        return $this->request->basePath() . $path . ($version ? '?v=' . $version : '');
    }

    public function current(bool $withQuery = true): string
    {
        $uri = $withQuery ? $this->request->uri() : $this->request->path();
        return $this->scheme() . '://' . $this->host() . $this->request->basePath() . $uri;
    }

    private function fromTrustedProxy(): bool
    {
        if ($this->trustedProxies === []) {
            return false;
        }
        if (in_array('*', $this->trustedProxies, true)) {
            return true;
        }
        return in_array((string) $this->request->server('REMOTE_ADDR'), $this->trustedProxies, true);
    }
}
