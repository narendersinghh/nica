<?php

declare(strict_types=1);

namespace App\Core;

/** Daily JSON-lines log files in storage/logs. */
final class Logger
{
    public function __construct(private string $dir)
    {
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
        $line = json_encode([
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($this->dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
