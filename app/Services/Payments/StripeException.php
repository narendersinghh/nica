<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class StripeException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $stripeCode = '', public readonly string $declineCode = '', public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }
}
