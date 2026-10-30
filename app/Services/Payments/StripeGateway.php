<?php

declare(strict_types=1);

namespace App\Services\Payments;

/** Stripe PaymentIntents with the Payment Element (cards, Link, wallets, Klarna, Afterpay...). */
final class StripeGateway implements PaymentGateway
{
    public function __construct(private StripeClient $client, private string $publishableKey)
    {
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function clientConfig(): array
    {
        return ['gateway' => 'stripe', 'publishableKey' => $this->publishableKey, 'testMode' => $this->client->isTestMode()];
    }

    public function createIntent(array $order): array
    {
        $intent = $this->client->request('POST', 'payment_intents', [
            'amount' => (int) $order['total_cents'],
            'currency' => strtolower($order['currency']),
            'automatic_payment_methods' => ['enabled' => 'true'],
            'description' => 'Order #' . $order['number'] . ' - ' . config('app.name'),
            'receipt_email' => $order['email'],
            'transfer_group' => 'order_' . $order['number'],
            'metadata' => ['order_id' => (string) $order['id'], 'order_number' => (string) $order['number']],
        ], 'pi_order_' . $order['id'] . '_' . $order['total_cents']);
        return ['intent_id' => (string) $intent['id'], 'client_secret' => (string) $intent['client_secret']];
    }

    public function cancelIntent(string $intentId): void
    {
        if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intentId)) {
            return;
        }
        try {
            $this->client->request('POST', 'payment_intents/' . $intentId . '/cancel', [], 'cancel_' . $intentId);
        } catch (StripeException $e) {
            // Already succeeded/cancelled: nothing to do. markPaid() handles a late success.
        }
    }

    public function fetchIntent(string $intentId): array
    {
        if (!preg_match('/^pi_[A-Za-z0-9]+$/', $intentId)) {
            throw new StripeException('Invalid payment reference.');
        }
        $pi = $this->client->request('GET', 'payment_intents/' . $intentId, ['expand' => ['latest_charge']]);
        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : null;
        return [
            'id' => (string) $pi['id'],
            'status' => (string) $pi['status'],
            'amount' => (int) $pi['amount'],
            'currency' => strtoupper((string) $pi['currency']),
            'metadata' => (array) ($pi['metadata'] ?? []),
            'charge_id' => $charge['id'] ?? (is_string($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : null),
            'method_type' => $charge['payment_method_details']['type'] ?? null,
        ];
    }

    public function transfer(int $amountCents, string $destinationAccount, string $transferGroup, ?string $sourceCharge, string $idempotencyKey): string
    {
        $params = ['amount' => $amountCents, 'currency' => 'usd', 'destination' => $destinationAccount, 'transfer_group' => $transferGroup];
        if ($sourceCharge) {
            $params['source_transaction'] = $sourceCharge; // funds become available with the charge
        }
        return (string) $this->client->request('POST', 'transfers', $params, $idempotencyKey)['id'];
    }

    public function refund(string $intentId, int $amountCents, string $idempotencyKey): array
    {
        return $this->client->request('POST', 'refunds', ['payment_intent' => $intentId, 'amount' => $amountCents], $idempotencyKey);
    }
}
