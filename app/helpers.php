<?php

declare(strict_types=1);

use App\Core\App;

/** No-op when mbstring is missing; the code base never relies on mb_* functions. */
function mb_internal_encoding_safe(): void
{
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }
}

function app(): App
{
    return App::instance();
}

function config(string $key, mixed $default = null): mixed
{
    return App::instance()->config()->get($key, $default);
}

/** Escape for HTML text and attribute context. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Absolute URL on the current (trusted) host. */
function url(string $path = '/', array $query = []): string
{
    return App::instance()->url()->to($path, $query);
}

function asset(string $path): string
{
    return App::instance()->url()->asset($path);
}

function route(string $name, array $params = [], array $query = []): string
{
    return App::instance()->url()->to(App::instance()->router()->path($name, $params), $query);
}

function csrf_token(): string
{
    // Cached pages carry no token; site.js fetches one from /csrf-token before a form is sent.
    if (App::instance()->renderingForCache()) {
        return '';
    }
    return App::instance()->csrf()->token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csp_nonce(): string
{
    return App::instance()->cspNonce();
}

/** Previously submitted form value (after a validation redirect). */
function old(string $key, string $default = ''): string
{
    $old = App::instance()->session()->getFlash('_old', []);
    return isset($old[$key]) && is_scalar($old[$key]) ? (string) $old[$key] : $default;
}

/** First $length characters of a UTF-8 string, without requiring mbstring. */
function mb_substr_safe(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    return preg_match('/^.{0,' . $length . '}/us', $value, $m) ? $m[0] : substr($value, 0, $length);
}

/** Public URL of a file in public/uploads (paths are stored relative, e.g. "2026/02/logo.webp"). */
function upload_url(?string $path): string
{
    if ($path === null || $path === '') {
        return '';
    }
    return App::instance()->request()->basePath() . '/uploads/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/** Inline SVG icon from the sprite in partials/icons.php. */
function icon(string $name, string $class = ''): string
{
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"></use></svg>';
}

/** Responsive <img> for a media id or uploads path. */
function image(string|int|null $ref, array $opts = []): string
{
    return App::instance()->images()->tag($ref, $opts);
}

function image_url(string|int|null $ref, ?string $size = null): string
{
    return App::instance()->images()->url($ref, $size);
}

/** Site-relative link for a stored path, respecting a sub-directory install. */
function link_to(string $path): string
{
    if (preg_match('#^(https?:|mailto:|tel:|\#)#i', $path)) {
        return $path;
    }
    return App::instance()->request()->basePath() . '/' . ltrim($path, '/');
}

/** True when $path is the current page (or, with $prefix, a page below it). */
function is_current(string $path, bool $prefix = false): bool
{
    $current = App::instance()->request()->path();
    if ($path === '/') {
        return $current === '/';
    }
    return $prefix ? str_starts_with($current, rtrim($path, '/')) : rtrim($current, '/') === rtrim($path, '/');
}

function money(int $cents, string $currency = 'USD'): string
{
    $symbol = $currency === 'USD' ? '$' : $currency . ' ';
    return ($cents < 0 ? '-' : '') . $symbol . number_format(abs($cents) / 100, 2);
}

/** UTF-8 aware string length without mbstring. */
function str_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (int) preg_match_all('/./us', $value);
}

/** Dashboard line icon (see partials/panel-icons). */
function picon(string $name, string $class = ''): string
{
    return '<svg class="picon' . ($class !== '' ? ' ' . e($class) : '') . '" aria-hidden="true" focusable="false"><use href="#p-' . e($name) . '"></use></svg>';
}
