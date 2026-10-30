<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    private array $headers = [];
    private array $cookies = [];

    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        return new self($json, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($text, $status, ['Content-Type' => $type . '; charset=UTF-8']);
    }

    /** Redirect to a path on this site or an absolute URL built by url(). */
    public static function redirect(string $to, int $status = 302): self
    {
        // Block header injection and protocol-relative open redirects.
        $to = str_replace(["\r", "\n"], '', $to);
        if (str_starts_with($to, '//')) {
            $to = '/' . ltrim($to, '/');
        }
        $location = preg_match('#^https?://#i', $to) ? $to : url($to);
        return new self('', $status, ['Location' => $location]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function header(string $name, string $value, bool $replace = true): self
    {
        $value = str_replace(["\r", "\n"], '', $value);
        $key = strtolower($name);
        if ($replace || !isset($this->headers[$key])) {
            $this->headers[$key] = [$name, $value];
        }
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }
        return $out;
    }

    public function hasCookies(): bool
    {
        return $this->cookies !== [];
    }

    /** Value of a cookie this response sets (tests and middleware). */
    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name][0] ?? null;
    }

    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[$name] = [$value, array_merge([
            'expires' => 0,
            'path' => '/',
            'secure' => app()->url()->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ], $options)];
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $name => [$value, $options]) {
                setcookie($name, $value, $options);
            }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $this->body;
        }
    }

    /**
     * Gzip text responses when the client accepts it and nothing else (PHP's
     * zlib.output_compression, or a proxy) already does. Apache's mod_deflate
     * leaves responses that are already encoded alone.
     */
    public function compress(string $acceptEncoding): self
    {
        $type = (string) ($this->getHeader('Content-Type') ?? 'text/html');
        if (strlen($this->body) < 1024 || !function_exists('gzencode') || ini_get('zlib.output_compression')
            || $this->getHeader('Content-Encoding') !== null || !preg_match('/\bgzip\b/i', $acceptEncoding)
            || !preg_match('#^(text/|application/(json|xml|javascript)|image/svg)#', $type)) {
            return $this;
        }
        $this->body = (string) gzencode($this->body, 6);
        return $this->header('Content-Encoding', 'gzip')->header('Vary', 'Accept-Encoding');
    }
}
