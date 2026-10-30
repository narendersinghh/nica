<?php

declare(strict_types=1);

namespace App\Core\Http;

/** An exception whose message is safe to show to the visitor. */
class HttpException extends \RuntimeException
{
    public function __construct(private int $status, string $message = '', private array $headers = [])
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Please log in to continue.',
            403 => 'You do not have permission to do that.',
            404 => 'Page not found.',
            405 => 'Method not allowed.',
            419 => 'Your session expired. Please refresh the page and try again.',
            422 => 'Please check the form and try again.',
            429 => 'Too many attempts. Please wait a moment and try again.',
            503 => 'We are doing some maintenance. Please check back shortly.',
            default => 'Something went wrong.',
        };
    }
}
