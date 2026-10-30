<?php

declare(strict_types=1);

namespace App\Services\Referrals;

use App\Core\Database;
use App\Core\Http\Request;
use App\Services\Settings;

/**
 * Stylist referrals: link clicks set a cookie, a paid order from that visitor
 * earns the stylist a tier-based commission per vendor part. The commission
 * comes out of the marketplace's own commission (capped by it), becomes
 * withdrawable when that part of the order is completed, and is clawed back
 * in proportion on refunds.
 */
final class Referrals
{
    public const COOKIE = 'nica_ref';

    public function __construct(private Database $db)
    {
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('referrals.enabled', true);
    }

    public static function normalize(?string $code): string
    {
        return substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $code)), 0, 20);
    }

    public function approved(?string $code): ?array
    {
        $code = self::normalize($code);
        if ($code === '' || !self::enabled()) {
            return null;
        }
        return $this->db->first("SELECT s.*, u.email FROM stylists s JOIN users u ON u.id = s.user_id WHERE s.code = ? AND s.status = 'approved' AND u.status = 'active'", [$code]);
    }

    /**
     * ?ref=CODE on any page: remember the stylist and count one visit per
     * visitor per day. Returns the cookie value to set, or null.
     */
    public function capture(Request $request): ?string
    {
        $stylist = $this->approved((string) $request->query('ref', ''));
        if ($stylist === null) {
            return null;
        }
        $visitor = hash_hmac('sha256', $request->ip() . '|' . gmdate('Y-m-d'), (string) config('app.key'));
        $host = parse_url((string) $request->header('Referer', ''), PHP_URL_HOST);
        try {
            $this->db->query(
                'INSERT IGNORE INTO referral_clicks (stylist_id, visitor_hash, landing_path, referrer_host) VALUES (?, ?, ?, ?)',
                [$stylist['id'], $visitor, mb_substr_safe('/' . ltrim($request->path(), '/'), 191), is_string($host) ? mb_substr_safe($host, 191) : null]
            );
        } catch (\PDOException) {
            // counting is best effort
        }
        return $stylist['code'];
    }

    /**
     * Code to store on a new order: typed at checkout, else from the link cookie.
     * A stylist never earns on their own purchases.
     * @throws \DomainException when a typed code is unknown
     */
    public function forCheckout(?string $typed, ?string $cookie, string $email, ?int $userId): ?string
    {
        $typed = self::normalize($typed);
        $stylist = $this->approved($typed !== '' ? $typed : $cookie);
        if ($stylist === null) {
            if ($typed !== '') {
                throw new \DomainException('We could not find that stylist code.');
            }
            return null;
        }
        if (($userId !== null && (int) $stylist['user_id'] === $userId) || strcasecmp($stylist['email'], $email) === 0) {
            return null;
        }
        return $stylist['code'];
    }

    /** @return array{key:string,name:string,rate:float,min_cents:int} plus progress to the next tier */
    public function tier(array $stylist): array
    {
        $tiers = (array) Settings::get('referrals.tiers', []);
        usort($tiers, static fn ($a, $b) => (int) $a['min_cents'] <=> (int) $b['min_cents']);
        $sales = $this->recentSales((int) $stylist['id']);
        $current = $tiers[0];
        $next = null;
        foreach ($tiers as $i => $t) {
            if ($sales >= (int) $t['min_cents']) {
                $current = $t;
                $next = $tiers[$i + 1] ?? null;
            }
        }
        if (!empty($stylist['tier_override'])) {
            foreach ($tiers as $t) {
                if ($t['key'] === $stylist['tier_override']) {
                    $current = $t;
                    $next = null;
                }
            }
        }
        return $current + ['sales_cents' => $sales, 'next' => $next, 'overridden' => !empty($stylist['tier_override'])];
    }

    public function recentSales(int $stylistId): int
    {
        return (int) $this->db->scalar(
            "SELECT COALESCE(SUM(base_cents), 0) FROM referrals WHERE stylist_id = ? AND status <> 'void' AND created_at >= UTC_TIMESTAMP() - INTERVAL ? DAY",
            [$stylistId, (int) Settings::get('referrals.tier_window_days', 90)]
        );
    }

    /** Called inside OrderService::markPaid's transaction. Safe to call twice. */
    public function recordForOrder(array $order): void
    {
        if (empty($order['referral_code']) || $this->db->scalar('SELECT 1 FROM referrals WHERE order_id = ?', [$order['id']])) {
            return;
        }
        $stylist = $this->db->first("SELECT s.*, u.email FROM stylists s JOIN users u ON u.id = s.user_id WHERE s.code = ? AND s.status = 'approved'", [self::normalize($order['referral_code'])]);
        if ($stylist === null || ($order['user_id'] && (int) $order['user_id'] === (int) $stylist['user_id']) || strcasecmp($stylist['email'], (string) $order['email']) === 0) {
            return;
        }
        $tier = $this->tier($stylist);
        $rate = (float) $tier['rate'];
        $base = 0;
        $total = 0;
        $rows = [];
        foreach ($this->db->select('SELECT * FROM vendor_orders WHERE order_id = ?', [$order['id']]) as $vo) {
            $voBase = max(0, (int) $vo['subtotal_cents'] - (int) $vo['discount_cents']);
            // Never more than the marketplace earns on this part of the order.
            $commission = min((int) round($voBase * $rate / 100), max(0, (int) $vo['commission_cents']));
            $base += $voBase;
            $total += $commission;
            if ($commission > 0) {
                $rows[] = [$vo, $commission];
            }
        }
        if ($base === 0) {
            return;
        }
        $this->db->insert('referrals', [
            'stylist_id' => (int) $stylist['id'], 'order_id' => (int) $order['id'], 'tier' => $tier['key'],
            'rate' => $rate, 'base_cents' => $base, 'commission_cents' => $total,
        ]);
        foreach ($rows as [$vo, $commission]) {
            $this->db->insert('stylist_ledger', [
                'stylist_id' => (int) $stylist['id'], 'order_id' => (int) $order['id'], 'vendor_order_id' => (int) $vo['id'],
                'type' => 'commission', 'amount_cents' => $commission,
                'description' => 'Order #' . $order['number'] . ' (' . rtrim(rtrim(number_format($rate, 2), '0'), '.') . '% ' . $tier['name'] . ')',
                'available_at' => null,
            ]);
        }
    }

    /** A vendor part was completed: its commission can be withdrawn. */
    public function release(int $vendorOrderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->query("UPDATE stylist_ledger SET available_at = ? WHERE vendor_order_id = ? AND available_at IS NULL", [$now, $vendorOrderId]);
        $orderId = (int) $this->db->scalar('SELECT order_id FROM vendor_orders WHERE id = ?', [$vendorOrderId]);
        if ($orderId && !$this->db->scalar("SELECT 1 FROM stylist_ledger WHERE order_id = ? AND type = 'commission' AND available_at IS NULL", [$orderId])) {
            $this->db->query("UPDATE referrals SET status = 'earned' WHERE order_id = ? AND status = 'pending'", [$orderId]);
        }
    }

    /** Part of a vendor order was refunded: take back the same share of the commission. */
    public function refund(array $vo, int $amountCents, array $order): void
    {
        $row = $this->db->first("SELECT * FROM stylist_ledger WHERE vendor_order_id = ? AND type = 'commission'", [$vo['id']]);
        if ($row === null || (int) $vo['total_cents'] <= 0) {
            return;
        }
        $already = -(int) $this->db->scalar("SELECT COALESCE(SUM(amount_cents), 0) FROM stylist_ledger WHERE vendor_order_id = ? AND type = 'refund'", [$vo['id']]);
        $share = min((int) round($amountCents * (int) $row['amount_cents'] / (int) $vo['total_cents']), (int) $row['amount_cents'] - $already);
        if ($share > 0) {
            $this->db->insert('stylist_ledger', [
                'stylist_id' => (int) $row['stylist_id'], 'order_id' => (int) $order['id'], 'vendor_order_id' => (int) $vo['id'],
                'type' => 'refund', 'amount_cents' => -$share, 'description' => 'Refund on order #' . $order['number'],
                'available_at' => $row['available_at'] !== null ? gmdate('Y-m-d H:i:s') : null,
            ]);
        }
        if (!$this->db->scalar("SELECT 1 FROM vendor_orders WHERE order_id = ? AND status <> 'refunded'", [$order['id']])) {
            $this->db->query("UPDATE referrals SET status = 'void' WHERE order_id = ?", [$order['id']]);
        }
    }

    /** A free, readable, unique code from the person's name ("JASMINE", "JASMINE2"…). */
    public function suggestCode(string $name): string
    {
        $base = substr(self::normalize($name), 0, 14);
        if (strlen($base) < 4) {
            $base = 'NICA' . $base;
        }
        $code = $base;
        for ($i = 2; $this->db->scalar('SELECT 1 FROM stylists WHERE code = ?', [$code]) || $this->db->scalar('SELECT 1 FROM coupons WHERE code = ?', [$code]); $i++) {
            $code = $base . $i;
        }
        return $code;
    }

    public function stats(int $stylistId): array
    {
        $r = $this->db->first(
            "SELECT COUNT(*) orders, COALESCE(SUM(base_cents), 0) sales, COALESCE(SUM(CASE WHEN status <> 'void' THEN commission_cents END), 0) commission
             FROM referrals WHERE stylist_id = ? AND status <> 'void'",
            [$stylistId]
        );
        $clicks = $this->db->first('SELECT COUNT(*) total, COUNT(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY THEN 1 END) recent FROM referral_clicks WHERE stylist_id = ?', [$stylistId]);
        return [
            'orders' => (int) $r['orders'], 'sales' => (int) $r['sales'], 'commission' => (int) $r['commission'],
            'clicks' => (int) $clicks['total'], 'clicks_30' => (int) $clicks['recent'],
            'conversion' => (int) $clicks['total'] > 0 ? round((int) $r['orders'] / (int) $clicks['total'] * 100, 1) : 0.0,
        ];
    }
}
