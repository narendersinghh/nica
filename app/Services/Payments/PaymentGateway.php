<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PaymentGateway
{
    public function name(): string;

    /** Public config for the browser (publishable key, mode). */
    public function clientConfig(): array;

    /** @return array{intent_id:string, client_secret:string} */
    public function createIntent(array $order): array;

    /** @return array{id:string,status:string,amount:int,currency:string,metadata:array,charge_id:?string,method_type:?string} */
    public function fetchIntent(string $intentId): array;

    /** Stripe Connect: send a vendor its share. Returns the transfer id. */
    public function transfer(int $amountCents, string $destinationAccount, string $transferGroup, ?string $sourceCharge, string $idempotencyKey): string;

    public function refund(string $intentId, int $amountCents, string $idempotencyKey): array;

    /** Make an unpaid intent unpayable (order cancelled). Already-final intents are left alone. */
    public function cancelIntent(string $intentId): void;
}
