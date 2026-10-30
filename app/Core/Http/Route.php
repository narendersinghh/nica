<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Route
{
    public ?string $name = null;
    public array $middleware = [];
    public bool $trailingSlash = true;
    private ?string $regex = null;
    private array $paramNames = [];

    /** @param callable|array{0:class-string,1:string} $handler */
    public function __construct(public array $methods, public string $pattern, public mixed $handler)
    {
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /** Routes like /api/... and *.xml do not use WordPress-style trailing slashes. */
    public function withoutTrailingSlash(): self
    {
        $this->trailingSlash = false;
        return $this;
    }

    /** @return array<string,string>|null */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex(), $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $m[$name] ?? '';
        }
        return $params;
    }

    public function build(array $params): string
    {
        $path = preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', static function (array $m) use ($params): string {
            if (!array_key_exists($m[1], $params)) {
                throw new \InvalidArgumentException("Missing route parameter '{$m[1]}'.");
            }
            // Keep "/" in multi-segment params such as collection paths.
            return implode('/', array_map('rawurlencode', explode('/', (string) $params[$m[1]])));
        }, $this->pattern);

        if ($path === '/' || !$this->trailingSlash) {
            return $path;
        }
        return rtrim($path, '/') . '/';
    }

    private function regex(): string
    {
        if ($this->regex !== null) {
            return $this->regex;
        }
        $pattern = rtrim($this->pattern, '/') ?: '/';
        // Split into literal text and {name} / {name:regex} placeholders; quote only the literals.
        $parts = preg_split('/(\{\w+(?::[^}]+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)(?::([^}]+))?\}$/', $part, $m)) {
                $this->paramNames[] = $m[1];
                $regex .= '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return $this->regex = '#^' . $regex . '$#u';
    }
}
