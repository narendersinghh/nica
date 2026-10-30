<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. Values are kept in an internal map (not putenv) so they
 * never leak into child processes or phpinfo().
 */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            self::$vars[$key] = self::parse($value);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $server = $_SERVER[$key] ?? getenv($key);
        return ($server === false || $server === null) ? $default : self::parse((string) $server);
    }

    public static function set(string $key, mixed $value): void
    {
        self::$vars[$key] = $value;
    }

    private static function parse(string $value): mixed
    {
        if ($value === '') {
            return '';
        }
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) > 1) {
            $value = substr($value, 1, -1);
            if ($quote === "'") {
                return $value;
            }
        } else {
            // Strip trailing inline comments on unquoted values.
            $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
        }

        $value = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/', static fn ($m) => (string) (self::$vars[$m[1]] ?? ''), $value);

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
