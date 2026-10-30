<?php

use App\Core\Env;

$env = (string) Env::get('APP_ENV', 'production');

return [
    'name' => (string) Env::get('APP_NAME', 'The NiCa Collection'),
    'env' => $env,
    'debug' => $env !== 'production' && (bool) Env::get('APP_DEBUG', false),
    'key' => (string) Env::get('APP_KEY', ''),
    'timezone' => (string) Env::get('APP_TIMEZONE', 'UTC'),

    // Host / URL resolution. The site URL is never hard-coded.
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_HOSTS', ''))))),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('TRUSTED_PROXIES', ''))))),
    'force_scheme' => (string) Env::get('FORCE_SCHEME', ''),

    'currency' => 'USD',
];
