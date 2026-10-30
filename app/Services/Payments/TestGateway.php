<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Local-only stand-in used when no Stripe keys are configured, so the whole
 * order flow can be tested. Payments::gateway() refuses it outside APP_ENV=local.
 */
final class TestGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'test';
    }

    public function clientConfig(): array
    {
        return ['gateway' => 'test'];
    }

    public function createIntent(array $order): array
    {
        $id = 'test_pi_' . $order['id'] . '_' . bin2hex(random_bytes(6));
        app()->db()->query("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [
            'test_intent.' . $id, json_encode(['amount' => (int) $order['total_cents'], 'status' => 'requires_payment_method', 'order_id' => (int) $order['id'], 'number' => $order['number']]),
        ]);
        return ['intent_id' => $id, 'client_secret' => $id . '_secret'];
    }

    /** Called by the local "Pay (test)" button: approve or decline. */
    public function settle(string $intentId, bool $approve): void
    {
        $row = $this->row($intentId);
        $row['status'] = $approve ? 'succeeded' : 'requires_payment_method';
        app()->db()->update('settings', ['value' => json_encode($row)], ['name' => 'test_intent.' . $intentId]);
    }

    public function fetchIntent(string $intentId): array
    {
        $row = $this->row($intentId);
        return ['id' => $intentId, 'status' => $row['status'], 'amount' => (int) $row['amount'], 'currency' => 'USD',
            'metadata' => ['order_id' => (string) $row['order_id'], 'order_number' => (string) $row['number']], 'charge_id' => 'test_ch_' . substr($intentId, 8), 'method_type' => 'card'];
    }

    public function transfer(int $amountCents, string $destinationAccount, string $transferGroup, ?string $sourceCharge, string $idempotencyKey): string
    {
        return 'test_tr_' . substr(hash('sha256', $idempotencyKey), 0, 16);
    }

    public function refund(string $intentId, int $amountCents, string $idempotencyKey): array
    {
        return ['id' => 'test_re_' . substr(hash('sha256', $idempotencyKey), 0, 16), 'status' => 'succeeded', 'amount' => $amountCents];
    }

    public function cancelIntent(string $intentId): void
    {
        // Local test payments: nothing to cancel remotely.
    }

    private function row(string $intentId): array
    {
        if (!preg_match('/^test_pi_\d+_[a-f0-9]{12}$/', $intentId)) {
            throw new StripeException('Invalid payment reference.');
        }
        $row = json_decode((string) app()->db()->scalar('SELECT value FROM settings WHERE name = ?', ['test_intent.' . $intentId]), true);
        if (!is_array($row)) {
            throw new StripeException('Unknown payment.');
        }
        return $row;
    }
}
