<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Serves minified copies of our own CSS/JS without a build step: the first
 * request after a source file changes writes public/assets/build/<name>.min.<ext>.
 * The minification is conservative (comments + whitespace only), so the
 * output always behaves exactly like the source.
 */
final class AssetMinifier
{
    /** @return string public path of the minified file, or the original path when it cannot be built */
    public static function resolve(string $publicDir, string $path): string
    {
        if (!preg_match('#^/assets/(css|js)/([a-z0-9_-]+)\.(css|js)$#', $path, $m) || $m[1] !== $m[3]) {
            return $path;
        }
        $source = $publicDir . $path;
        $built = '/assets/build/' . $m[2] . '.min.' . $m[3];
        $target = $publicDir . $built;
        if (!is_file($source)) {
            return $path;
        }
        if (!is_file($target) || filemtime($target) < filemtime($source)) {
            $code = (string) file_get_contents($source);
            $min = $m[3] === 'css' ? self::css($code) : self::js($code);
            $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!is_dir(dirname($target)) || @file_put_contents($tmp, $min) === false || !@rename($tmp, $target)) {
                @unlink($tmp);
                return $path;
            }
            @touch($target, filemtime($source));
        }
        return $built;
    }

    public static function css(string $css): string
    {
        // Keep strings and url() intact; minify everything between them.
        $parts = preg_split('/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|url\([^)]*\))/', $css, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$css];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= $part;
                continue;
            }
            $part = (string) preg_replace('#/\*.*?\*/#s', '', $part);
            $part = (string) preg_replace('/\s+/', ' ', $part);
            $part = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $part);
            $part = (string) preg_replace('/:\s+/', ':', $part);
            $part = str_replace(';}', '}', $part);
            $out .= $part;
        }
        // "and(" in media queries needs its space back.
        return trim((string) preg_replace('/\band\(/', 'and (', $out)) . "\n";
    }

    public static function js(string $js): string
    {
        $lines = [];
        $inBlockComment = false;
        foreach (preg_split('/\R/', $js) ?: [] as $line) {
            $trim = trim($line);
            if ($inBlockComment) {
                if (str_contains($trim, '*/')) {
                    $inBlockComment = false;
                }
                continue;
            }
            // Only whole-line comments are removed (never anything inside code or strings).
            if ($trim === '' || str_starts_with($trim, '//')) {
                continue;
            }
            if (str_starts_with($trim, '/*')) {
                if (!str_contains($trim, '*/')) {
                    $inBlockComment = true;
                }
                if (!str_contains($trim, '*/') || str_ends_with($trim, '*/')) {
                    continue;
                }
            }
            $lines[] = $trim;
        }
        return implode("\n", $lines) . "\n";
    }
}
