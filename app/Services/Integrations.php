<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Marketing/analytics integrations (GA4, Meta Pixel, Klaviyo). Everything is
 * off until an ID is saved in Admin → Settings. Browser tags are loaded by
 * assets/js/consent.js only after the visitor accepts cookies, so they never
 * block rendering, and the CSP is widened only for the services switched on.
 * Server-side events (purchase) go through Integrations::event(), a no-op
 * until a server connector (Meta CAPI, Klaviyo API) is added.
 */
final class Integrations
{
    public static function ga4(): ?string
    {
        $id = trim((string) Settings::get('integrations.ga4.measurement_id', ''));
        return preg_match('/^G-[A-Z0-9]{4,15}$/', $id) ? $id : null;
    }

    public static function metaPixel(): ?string
    {
        $id = trim((string) Settings::get('integrations.meta_pixel.pixel_id', ''));
        return preg_match('/^\d{6,20}$/', $id) ? $id : null;
    }

    public static function klaviyo(): ?string
    {
        $key = trim((string) Settings::get('integrations.klaviyo.public_key', ''));
        return preg_match('/^[A-Za-z0-9]{6}$/', $key) ? $key : null;
    }

    public static function anyTracking(): bool
    {
        return self::ga4() !== null || self::metaPixel() !== null || self::klaviyo() !== null;
    }

    /** Config handed to consent.js (IDs only, nothing secret). */
    public static function clientConfig(): array
    {
        return array_filter(['ga4' => self::ga4(), 'meta' => self::metaPixel(), 'klaviyo' => self::klaviyo()]);
    }

    /** Extra CSP sources for the services that are switched on. */
    public static function cspSources(): array
    {
        $csp = [];
        $add = static function (array $map) use (&$csp): void {
            foreach ($map as $directive => $sources) {
                $csp[$directive] = array_merge($csp[$directive] ?? [], $sources);
            }
        };
        try {
            if (self::ga4()) {
                $add(['script-src' => ['https://www.googletagmanager.com'], 'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com'], 'img-src' => ['https://*.google-analytics.com', 'https://www.googletagmanager.com']]);
            }
            if (self::metaPixel()) {
                $add(['script-src' => ['https://connect.facebook.net'], 'connect-src' => ['https://www.facebook.com', 'https://connect.facebook.net'], 'img-src' => ['https://www.facebook.com'], 'frame-src' => ['https://www.facebook.com']]);
            }
            if (self::klaviyo()) {
                $add(['script-src' => ['https://static.klaviyo.com', 'https://static-tracking.klaviyo.com'], 'connect-src' => ['https://a.klaviyo.com', 'https://static.klaviyo.com', 'https://static-tracking.klaviyo.com'], 'img-src' => ['https://static.klaviyo.com'], 'style-src' => ['https://static.klaviyo.com'], 'font-src' => ['https://static.klaviyo.com']]);
            }
        } catch (\Throwable) {
            // settings unavailable (e.g. database down): no third-party sources
        }
        return $csp;
    }

    /**
     * Server-side event hook (e.g. 'purchase' after payment). Kept as the one
     * place to connect Meta Conversions API / Klaviyo server events later.
     */
    public static function event(string $name, array $data): void
    {
        if (!self::anyTracking()) {
            return;
        }
        app()->logger()->info('integration event', ['event' => $name, 'order' => $data['number'] ?? null]);
    }
}
