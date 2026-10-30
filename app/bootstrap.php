<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// PSR-4 autoloader for the App\ namespace (no Composer needed).
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');

date_default_timezone_set((string) App\Core\Env::get('APP_TIMEZONE', 'UTC'));
mb_internal_encoding_safe();

$app = new App\Core\App(BASE_PATH);
App\Core\ErrorHandler::register($app);

return $app;
