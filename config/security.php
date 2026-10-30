<?php

use App\Core\Env;

return [
    'session' => [
        'name' => 'nica_session',
        'idle_minutes' => (int) Env::get('SESSION_LIFETIME_MINUTES', 120),
        'absolute_hours' => (int) Env::get('SESSION_ABSOLUTE_HOURS', 24),
    ],

    // Paths that skip CSRF checks (signed by their own mechanism, e.g. Stripe signatures).
    'csrf_except' => [
        '/webhooks/*',
    ],

    // Extra origins allowed by the Content-Security-Policy, per directive.
    // Integrations (GA4, Meta, Klaviyo) add theirs here when they are switched on.
    'csp' => [
        // Stripe.js / Payment Element (per Stripe's CSP guide).
        'script-src' => ['https://js.stripe.com', 'https://*.js.stripe.com'],
        // Google Maps embed on the contact page.
        'frame-src' => ['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com', 'https://maps.google.com', 'https://www.google.com'],
        'connect-src' => ['https://api.stripe.com', 'https://*.stripe.com'],
        'img-src' => ['data:', 'blob:', 'https://*.stripe.com'], // blob: = upload previews in the dashboards
        'style-src' => [],
        'font-src' => [],
        'media-src' => [],
    ],

    'password' => [
        'min_length' => 10,
    ],

    // name => [max attempts, decay seconds]
    'rate_limits' => [
        'login' => [5, 900],          // per IP and per account
        'register' => [5, 3600],
        'password_reset' => [5, 3600],
        'contact' => [5, 3600],
        'api' => [120, 60],
        'cart' => [120, 60],
        'coupon' => [15, 600],   // slows down coupon-code guessing
        'checkout' => [20, 600],
        'payout' => [10, 3600],
        'updates' => [10, 3600],
    ],
];
