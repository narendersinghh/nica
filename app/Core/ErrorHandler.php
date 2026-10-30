<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Response;

/**
 * Turns PHP errors into exceptions, logs everything, and never shows internals
 * outside local debug mode.
 */
final class ErrorHandler
{
    public static function register(App $app): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($app): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            // Deprecations are warnings about future PHP versions: log them, never break a page over one.
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                $app->logger()->warning('PHP deprecation: ' . $message, ['file' => $file . ':' . $line]);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (\Throwable $e) use ($app): void {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
                exit(1);
            }
            self::render($app, $e)->send();
        });

        register_shutdown_function(static function () use ($app): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $app->logger()->error('Fatal error', $error);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                    echo 'Something went wrong.';
                }
            }
        });
    }

    public static function render(App $app, \Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->status() : 500;

        if ($status >= 500) {
            $app->logger()->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ]);
        }

        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $message = $e instanceof HttpException ? $e->getMessage() : 'Something went wrong.';

        if ($wantsJson) {
            $body = ['error' => $message];
            if ($app->debug()) {
                $body['debug'] = ['exception' => get_class($e), 'message' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()];
            }
            return Response::json($body, $status);
        }

        try {
            $html = $app->view()->render('errors/error', [
                'status' => $status,
                'message' => $message,
                'exception' => $app->debug() && $status >= 500 ? $e : null,
            ]);
        } catch (\Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status);
    }
}
