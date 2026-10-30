<?php

use App\Core\Env;

return [
    'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => (string) Env::get('DB_DATABASE', 'nica'),
    'username' => (string) Env::get('DB_USERNAME', 'nica'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
