<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Env;

final class Payments
{
    private static ?PaymentGateway $gateway = null;

    /** Stripe when keys are configured; the test gateway only in local mode; otherwise none. */
    public static function gateway(): ?PaymentGateway
    {
        if (self::$gateway !== null) {
            return self::$gateway;
        }
        $secret = (string) Env::get('STRIPE_SECRET_KEY', '');
        $public = (string) Env::get('STRIPE_PUBLISHABLE_KEY', '');
        if ($secret !== '' && $public !== '') {
            return self::$gateway = new StripeGateway(new StripeClient($secret), $public);
        }
        if (app()->isLocal()) {
            return self::$gateway = new TestGateway();
        }
        return null;
    }

    public static function forIntent(string $intentId): ?PaymentGateway
    {
        if (str_starts_with($intentId, 'test_pi_')) {
            return app()->isLocal() ? new TestGateway() : null;
        }
        $gateway = self::gateway();
        return $gateway instanceof StripeGateway ? $gateway : null;
    }

    public static function use(?PaymentGateway $gateway): void
    {
        self::$gateway = $gateway; // tests
    }
}
