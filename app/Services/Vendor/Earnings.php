<?php

declare(strict_types=1);

namespace App\Services\Vendor;

use App\Core\Database;
use App\Core\Security\Crypto;

/**
 * Vendor (and stylist) money. Every movement is a row in the owner's ledger
 * (vendor_ledger / stylist_ledger):
 *   earning (+)   when an order is paid; withdrawable once the vendor order is completed
 *   transfer (-)  paid out instantly through Stripe Connect
 *   payout (-)    a manual payout (PayPal / bank) marked paid by an admin
 *   refund (-), adjustment (+/-)
 * Balance = sum of rows that are available now. Open payout requests are held
 * back so the same money cannot be requested twice.
 */
final class Earnings
{
    public const METHODS = ['paypal' => 'PayPal', 'bank' => 'Bank transfer'];
    private const CRYPTO_PURPOSE = 'nica-payout-details';

    public const VENDOR = 'vendor';
    public const STYLIST = 'stylist';

    private string $ledgerTable;
    private string $requestTable;
    private string $ownerTable;
    private string $fk;

    /** @param string $owner self::VENDOR or self::STYLIST (same rules, separate books) */
    public function __construct(private Database $db, private string $owner = self::VENDOR)
    {
        [$this->ledgerTable, $this->requestTable, $this->ownerTable, $this->fk] = $owner === self::STYLIST
            ? ['stylist_ledger', 'stylist_payout_requests', 'stylists', 'stylist_id']
            : ['vendor_ledger', 'payout_requests', 'vendors', 'vendor_id'];
    }

    /** @return array{available:int, pending:int, reserved:int, withdrawable:int, lifetime:int, paid_out:int} */
    public function summary(int $vendorId): array
    {
        $row = $this->db->first(
            "SELECT
                COALESCE(SUM(CASE WHEN available_at IS NOT NULL AND available_at <= UTC_TIMESTAMP() THEN amount_cents END), 0) AS available,
                COALESCE(SUM(CASE WHEN available_at IS NULL OR available_at > UTC_TIMESTAMP() THEN amount_cents END), 0) AS pending,
                COALESCE(SUM(CASE WHEN type IN ('earning','commission','refund') THEN amount_cents END), 0) AS lifetime,
                COALESCE(-SUM(CASE WHEN type IN ('payout','transfer') THEN amount_cents END), 0) AS paid_out
             FROM {$this->ledgerTable} WHERE {$this->fk} = ?",
            [$vendorId]
        );
        $reserved = (int) $this->db->scalar("SELECT COALESCE(SUM(amount_cents), 0) FROM {$this->requestTable} WHERE {$this->fk} = ? AND status IN ('pending','approved')", [$vendorId]);
        $available = (int) $row['available'];
        return [
            'available' => $available,
            'pending' => (int) $row['pending'],
            'reserved' => $reserved,
            'withdrawable' => max(0, $available - $reserved),
            'lifetime' => (int) $row['lifetime'],
            'paid_out' => (int) $row['paid_out'],
        ];
    }

    public function threshold(array $vendor): int
    {
        $default = $this->owner === self::STYLIST ? 'referrals.payout_threshold_cents' : 'commerce.payout_threshold_cents';
        return $vendor['payout_threshold_cents'] !== null ? (int) $vendor['payout_threshold_cents'] : (int) \App\Services\Settings::get($default, 5000);
    }

    public function ledger(int $vendorId, int $page = 1, int $perPage = 20): array
    {
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM {$this->ledgerTable} WHERE {$this->fk} = ?", [$vendorId]);
        $orderJoin = $this->owner === self::STYLIST
            ? 'LEFT JOIN orders o ON o.id = l.order_id'
            : 'LEFT JOIN vendor_orders vo ON vo.id = l.vendor_order_id LEFT JOIN orders o ON o.id = vo.order_id';
        $rows = $this->db->select(
            "SELECT l.*, o.number AS order_number FROM {$this->ledgerTable} l {$orderJoin}
             WHERE l.{$this->fk} = ? ORDER BY l.created_at DESC, l.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            [$vendorId]
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage)), 'page' => $page];
    }

    public function requests(int $vendorId, int $limit = 50): array
    {
        return $this->db->select("SELECT id, amount_cents, method, status, vendor_note, admin_note, reference, processed_at, created_at FROM {$this->requestTable} WHERE {$this->fk} = ? ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit, [$vendorId]);
    }

    /** Decrypted payout details, or null when none are saved. */
    public function details(array $vendor): ?array
    {
        $plain = Crypto::decrypt($vendor['payout_details'] ?? null, self::CRYPTO_PURPOSE);
        $data = $plain !== null ? json_decode($plain, true) : null;
        return is_array($data) ? $data : null;
    }

    public function saveDetails(int $vendorId, string $method, array $details): void
    {
        $this->db->update($this->ownerTable, [
            'payout_method' => $method,
            'payout_details' => Crypto::encrypt(json_encode($details, JSON_UNESCAPED_UNICODE), self::CRYPTO_PURPOSE),
        ], ['id' => $vendorId]);
    }

    /** Short masked description, safe to show anywhere ("PayPal · j***@gmail.com"). */
    public static function mask(?string $method, ?array $details): string
    {
        if ($method === 'paypal' && !empty($details['email'])) {
            [$user, $domain] = array_pad(explode('@', (string) $details['email'], 2), 2, '');
            return 'PayPal · ' . mb_substr_safe($user, 1) . '***@' . $domain;
        }
        if ($method === 'bank' && !empty($details['account_number'])) {
            return 'Bank · ' . ($details['bank_name'] ?? '') . ' ••••' . substr((string) $details['account_number'], -4);
        }
        return 'Not set up';
    }

    /**
     * @throws \DomainException with a user-facing message
     * @return int request id
     */
    public function request(int $vendorId, int $amountCents, string $note = ''): int
    {
        return $this->db->transaction(function () use ($vendorId, $amountCents, $note) {
            // Lock the store row so two simultaneous requests cannot both pass the balance check.
            $vendor = $this->db->first("SELECT * FROM {$this->ownerTable} WHERE id = ? FOR UPDATE", [$vendorId]);
            if ($vendor === null || $vendor['status'] !== 'approved') {
                throw new \DomainException('Your account must be approved before you can request a payout.');
            }
            $details = $this->details($vendor);
            if ($vendor['payout_method'] === null || $details === null) {
                throw new \DomainException('Add your PayPal or bank details in Payout Settings first.');
            }
            if ($this->db->scalar("SELECT 1 FROM {$this->requestTable} WHERE {$this->fk} = ? AND status = 'pending'", [$vendorId])) {
                throw new \DomainException('You already have a payout request waiting. You can cancel it and send a new one.');
            }
            $threshold = $this->threshold($vendor);
            $summary = $this->summary($vendorId);
            if ($amountCents < $threshold) {
                throw new \DomainException('The minimum payout is ' . money($threshold) . '.');
            }
            if ($amountCents > $summary['withdrawable']) {
                throw new \DomainException('You can request up to ' . money($summary['withdrawable']) . '.');
            }
            return $this->db->insert($this->requestTable, [
                $this->fk => $vendorId,
                'amount_cents' => $amountCents,
                'method' => $vendor['payout_method'],
                'details_snapshot' => $vendor['payout_details'],
                'vendor_note' => $note !== '' ? mb_substr_safe($note, 500) : null,
            ]);
        });
    }

    public function cancel(int $vendorId, int $requestId): bool
    {
        return $this->db->query("UPDATE {$this->requestTable} SET status = 'cancelled', processed_at = UTC_TIMESTAMP() WHERE id = ? AND {$this->fk} = ? AND status = 'pending'", [$requestId, $vendorId])->rowCount() === 1;
    }

    /** Admin: decline a request; the held money becomes withdrawable again. */
    public function reject(int $requestId, ?int $adminId, string $note): bool
    {
        return $this->db->query(
            "UPDATE {$this->requestTable} SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('pending','approved')",
            [mb_substr_safe($note, 500), $adminId, $requestId]
        )->rowCount() === 1;
    }

    /** Admin: manual correction (bonus, chargeback, fee...). Positive or negative, available now. */
    public function adjust(int $vendorId, int $amountCents, string $description, ?int $adminId): void
    {
        if ($amountCents === 0) {
            throw new \DomainException('Enter an amount other than zero.');
        }
        $this->db->insert($this->ledgerTable, [
            $this->fk => $vendorId, 'type' => 'adjustment', 'amount_cents' => $amountCents,
            'description' => mb_substr_safe($description, 255), 'available_at' => gmdate('Y-m-d H:i:s'),
            'reference' => $adminId ? 'admin:' . $adminId : null,
        ]);
    }

    /** Decrypted bank/PayPal details as they were when the request was made. */
    public function requestDetails(array $request): ?array
    {
        $plain = Crypto::decrypt($request['details_snapshot'] ?? null, self::CRYPTO_PURPOSE);
        $data = $plain !== null ? json_decode($plain, true) : null;
        return is_array($data) ? $data : null;
    }

    /** Admin: money has been sent. Writes the ledger debit exactly once. */
    public function markPaid(int $requestId, ?int $adminId, string $reference = ''): void
    {
        $this->db->transaction(function () use ($requestId, $adminId, $reference) {
            $req = $this->db->first("SELECT * FROM {$this->requestTable} WHERE id = ? FOR UPDATE", [$requestId]);
            if ($req === null || !in_array($req['status'], ['pending', 'approved'], true)) {
                throw new \DomainException('This payout request is not open.');
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->db->update($this->requestTable, ['status' => 'paid', 'reference' => $reference !== '' ? mb_substr_safe($reference, 100) : null, 'processed_by' => $adminId, 'processed_at' => $now], ['id' => $requestId]);
            $this->db->insert($this->ledgerTable, [
                $this->fk => (int) $req[$this->fk],
                'type' => 'payout',
                'amount_cents' => -(int) $req['amount_cents'],
                'description' => 'Payout via ' . (self::METHODS[$req['method']] ?? $req['method']),
                'available_at' => $now,
                'reference' => 'payout_request:' . $requestId,
            ]);
        });
    }
}
