<?php

declare(strict_types=1);

namespace App\Services\Cache;

/**
 * Full-page cache for anonymous visitors (files in storage/cache/pages).
 * Entries are keyed by host + path and a global version: any change made in
 * the admin panel or a vendor dashboard, or a stock change from an order,
 * bumps the version so every page is rebuilt on its next visit. Entries also
 * expire after a short TTL so scheduled sale prices start and end on time.
 */
final class PageCache
{
    private const TTL = 600;

    public static function dir(): string
    {
        return app()->path('storage/cache/pages');
    }

    public static function enabled(): bool
    {
        return (bool) config('app.page_cache', true);
    }

    public static function key(string $host, string $path): string
    {
        return hash('sha256', strtolower($host) . '|' . $path . '|' . self::version());
    }

    public static function version(): string
    {
        $file = self::dir() . '/VERSION';
        // A deploy that changes the main assets also invalidates every cached page.
        $assets = (int) @filemtime(app()->path('public/assets/css/site.css')) . '.' . (int) @filemtime(app()->path('public/assets/js/site.js'));
        return (is_file($file) ? (string) file_get_contents($file) : '0') . '|' . $assets;
    }

    /** Invalidate everything (cheap: old entries are ignored and swept by queue:work). */
    public static function flush(): void
    {
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        @file_put_contents(self::dir() . '/VERSION', bin2hex(random_bytes(6)), LOCK_EX);
    }

    /** @return array{html:string, nonce:string, headers:array}|null */
    public static function get(string $key): ?array
    {
        $file = self::dir() . '/' . $key . '.cache';
        if (!is_file($file) || filemtime($file) < time() - self::TTL) {
            return null;
        }
        $entry = @unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        return is_array($entry) && isset($entry['html'], $entry['nonce']) ? $entry : null;
    }

    public static function put(string $key, string $html, string $nonce, array $headers): void
    {
        if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0775, true) && !is_dir(self::dir())) {
            return;
        }
        $tmp = self::dir() . '/' . $key . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize(['html' => $html, 'nonce' => $nonce, 'headers' => $headers])) !== false) {
            @rename($tmp, self::dir() . '/' . $key . '.cache');
        }
    }

    /** Remove expired entries; returns how many were deleted. */
    public static function sweep(): int
    {
        $n = 0;
        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            if (filemtime($file) < time() - self::TTL) {
                @unlink($file);
                $n++;
            }
        }
        foreach (glob(self::dir() . '/*.tmp') ?: [] as $file) {
            if (filemtime($file) < time() - 60) {
                @unlink($file);
            }
        }
        return $n;
    }
}
