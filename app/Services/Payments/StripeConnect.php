<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Core\Env;

/**
 * Stripe Connect Express onboarding for vendors. Once a vendor's account can
 * take charges and receive payouts, new orders split automatically (see
 * OrderService: payout_mode = stripe_connect). Without Stripe keys, local
 * installs get a simulated account so the split flow can be tested.
 */
final class StripeConnect
{
    public function __construct(private Database $db, private ?StripeClient $client = null)
    {
        if ($this->client === null && ($secret = (string) Env::get('STRIPE_SECRET_KEY', '')) !== '') {
            $this->client = new StripeClient($secret);
        }
    }

    public function available(): bool
    {
        return $this->client !== null || $this->simulated();
    }

    public function simulated(): bool
    {
        return $this->client === null && app()->isLocal();
    }

    /**
     * URL to send the vendor to (Stripe-hosted onboarding).
     * @throws StripeException
     */
    public function onboardingUrl(array $vendor, string $email, string $returnUrl, string $refreshUrl): string
    {
        if ($this->simulated()) {
            $this->db->update('vendors', [
                'stripe_account_id' => 'acct_test_' . $vendor['id'],
                'stripe_charges_enabled' => 1,
                'stripe_payouts_enabled' => 1,
                'stripe_onboarded_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int) $vendor['id']]);
            return $returnUrl;
        }
        if ($this->client === null) {
            throw new StripeException('Stripe is not configured.');
        }
        $accountId = (string) ($vendor['stripe_account_id'] ?? '');
        if ($accountId === '') {
            $account = $this->client->request('POST', 'accounts', [
                'type' => 'express',
                'country' => 'US',
                'email' => $email,
                'capabilities' => ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']],
                'business_profile' => ['name' => $vendor['store_name'], 'url' => url('/store/' . $vendor['slug'] . '/')],
                'metadata' => ['vendor_id' => (string) $vendor['id']],
            ], 'connect_account_vendor_' . $vendor['id']);
            $accountId = (string) $account['id'];
            $this->db->update('vendors', ['stripe_account_id' => $accountId], ['id' => (int) $vendor['id']]);
        }
        $link = $this->client->request('POST', 'account_links', [
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);
        return (string) $link['url'];
    }

    /** Pull the account's current state from Stripe and store it. */
    public function refresh(array $vendor): array
    {
        $accountId = (string) ($vendor['stripe_account_id'] ?? '');
        if ($accountId === '' || $this->client === null) {
            return $vendor;
        }
        $this->apply($this->client->request('GET', 'accounts/' . rawurlencode($accountId)));
        return $this->db->first('SELECT * FROM vendors WHERE id = ?', [$vendor['id']]) ?? $vendor;
    }

    /** account.updated webhook or a refresh. */
    public function apply(array $account): void
    {
        $charges = !empty($account['charges_enabled']) ? 1 : 0;
        $payouts = !empty($account['payouts_enabled']) ? 1 : 0;
        $this->db->query(
            'UPDATE vendors SET stripe_charges_enabled = ?, stripe_payouts_enabled = ?, stripe_onboarded_at = IF(? = 1 AND ? = 1, COALESCE(stripe_onboarded_at, UTC_TIMESTAMP()), stripe_onboarded_at) WHERE stripe_account_id = ?',
            [$charges, $payouts, $charges, $payouts, (string) ($account['id'] ?? '')]
        );
    }

    /** One-time link to the vendor's Stripe Express dashboard. */
    public function dashboardUrl(array $vendor): ?string
    {
        if ($this->client === null || empty($vendor['stripe_account_id'])) {
            return null;
        }
        return (string) ($this->client->request('POST', 'accounts/' . rawurlencode($vendor['stripe_account_id']) . '/login_links')['url'] ?? '') ?: null;
    }

    /** Stop splitting payments for this vendor (they go back to manual payouts). */
    public function disconnect(int $vendorId): void
    {
        $this->db->update('vendors', ['stripe_account_id' => null, 'stripe_charges_enabled' => 0, 'stripe_payouts_enabled' => 0, 'stripe_onboarded_at' => null], ['id' => $vendorId]);
    }

    public static function isConnected(array $vendor): bool
    {
        return !empty($vendor['stripe_account_id']) && (int) $vendor['stripe_charges_enabled'] === 1 && (int) $vendor['stripe_payouts_enabled'] === 1;
    }
}
