<?php

declare(strict_types=1);

namespace App\Services\Vendor;

use App\Core\Database;
use App\Services\Mail\Mailer;
use App\Services\Orders\OrderService;
use App\Services\Referrals\Referrals;

/**
 * A vendor's side of an order (one vendor_orders row): listing, shipping with
 * tracking and completion. Completing releases the earning for payout.
 */
final class VendorOrders
{
    public const STATUS_LABELS = [
        'pending' => 'Pending payment', 'processing' => 'Processing', 'on-hold' => 'On hold', 'shipped' => 'Shipped',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed',
    ];
    public const CARRIERS = [
        'usps' => ['USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s'],
        'ups' => ['UPS', 'https://www.ups.com/track?tracknum=%s'],
        'fedex' => ['FedEx', 'https://www.fedex.com/fedextrack/?trknbr=%s'],
        'dhl' => ['DHL', 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id=%s'],
        'other' => ['Other', null],
    ];

    public function __construct(private Database $db)
    {
    }

    public function counts(int $vendorId): array
    {
        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($this->db->select('SELECT status, COUNT(*) n FROM vendor_orders WHERE vendor_id = ? GROUP BY status', [$vendorId]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    public function list(int $vendorId, ?string $status, string $search, int $page, int $perPage = 20): array
    {
        $where = 'vo.vendor_id = ?';
        $params = [$vendorId];
        if ($status !== null && isset(self::STATUS_LABELS[$status])) {
            $where .= ' AND vo.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= " AND (o.number = ? OR o.email LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(o.billing_address, '$.last_name')) LIKE ?)";
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
            array_push($params, ltrim($search, '#'), $like, $like);
        }
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM vendor_orders vo JOIN orders o ON o.id = vo.order_id WHERE {$where}", $params);
        $rows = $this->db->select(
            "SELECT vo.*, o.number, o.email, o.billing_address, o.created_at AS ordered_at,
                    (SELECT SUM(quantity) FROM order_items oi WHERE oi.vendor_order_id = vo.id) AS item_count
             FROM vendor_orders vo JOIN orders o ON o.id = vo.order_id
             WHERE {$where} ORDER BY o.created_at DESC, vo.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage)), 'page' => $page];
    }

    /** The vendor order with its parent order, items and notes, or null when it is not this vendor's. */
    public function find(int $vendorId, int $vendorOrderId): ?array
    {
        $vo = $this->db->first('SELECT * FROM vendor_orders WHERE id = ? AND vendor_id = ?', [$vendorOrderId, $vendorId]);
        if ($vo === null) {
            return null;
        }
        $vo['order'] = $this->db->first('SELECT * FROM orders WHERE id = ?', [$vo['order_id']]);
        $vo['items'] = $this->db->select('SELECT * FROM order_items WHERE vendor_order_id = ? ORDER BY id', [$vo['id']]);
        $vo['notes'] = $this->db->select('SELECT * FROM order_notes WHERE order_id = ? AND (vendor_order_id = ? OR (vendor_order_id IS NULL AND is_customer_visible = 1)) ORDER BY created_at DESC, id DESC', [$vo['order_id'], $vo['id']]);
        return $vo;
    }

    /** @throws \DomainException */
    public function ship(int $vendorId, int $vendorOrderId, string $carrier, string $number, ?int $userId): void
    {
        $vo = $this->lock($vendorId, $vendorOrderId, ['processing', 'shipped']);
        $carrier = isset(self::CARRIERS[$carrier]) ? $carrier : 'other';
        $number = trim($number);
        $pattern = self::CARRIERS[$carrier][1];
        $this->db->update('vendor_orders', [
            'status' => 'shipped',
            'tracking_carrier' => self::CARRIERS[$carrier][0],
            'tracking_number' => mb_substr_safe($number, 100),
            'tracking_url' => $pattern !== null ? sprintf($pattern, rawurlencode($number)) : null,
            'shipped_at' => $vo['shipped_at'] ?? gmdate('Y-m-d H:i:s'),
        ], ['id' => $vendorOrderId]);

        $store = (string) $this->db->scalar('SELECT store_name FROM vendors WHERE id = ?', [$vendorId]);
        $orders = new OrderService($this->db);
        $orders->note((int) $vo['order_id'], $store . ' shipped your items with ' . self::CARRIERS[$carrier][0] . ' (tracking ' . $number . ').', true, $vendorOrderId, $userId);

        $order = $orders->find((int) $vo['order_id']);
        $fresh = $this->db->first('SELECT * FROM vendor_orders WHERE id = ?', [$vendorOrderId]);
        (new Mailer())->queue($order['email'], 'Your ' . config('app.name') . ' order #' . $order['number'] . ' is on its way',
            app()->view()->render('emails/order-shipped', ['order' => $order, 'vo' => $fresh, 'store' => $store, 'items' => $this->db->select('SELECT * FROM order_items WHERE vendor_order_id = ?', [$vendorOrderId])]));
    }

    /**
     * Mark delivered/complete. The earning becomes withdrawable now, and the
     * customer order completes once every vendor's part is done.
     * @throws \DomainException
     */
    public function complete(int $vendorId, int $vendorOrderId, ?int $userId): void
    {
        $this->db->transaction(function () use ($vendorId, $vendorOrderId, $userId) {
            $vo = $this->lock($vendorId, $vendorOrderId, ['processing', 'shipped']);
            $now = gmdate('Y-m-d H:i:s');
            $this->db->update('vendor_orders', ['status' => 'completed', 'completed_at' => $now], ['id' => $vendorOrderId]);
            $this->db->query("UPDATE vendor_ledger SET available_at = ? WHERE vendor_order_id = ? AND type = 'earning' AND available_at IS NULL", [$now, $vendorOrderId]);
            (new Referrals($this->db))->release($vendorOrderId);
            (new OrderService($this->db))->note((int) $vo['order_id'], 'Vendor marked their items as completed.', false, $vendorOrderId, $userId);

            $open = (int) $this->db->scalar("SELECT COUNT(*) FROM vendor_orders WHERE order_id = ? AND status NOT IN ('completed','cancelled','refunded')", [$vo['order_id']]);
            if ($open === 0) {
                $this->db->query("UPDATE orders SET status = 'completed', completed_at = ? WHERE id = ? AND status IN ('processing','on-hold')", [$now, $vo['order_id']]);
            }
        });
    }

    public function addNote(int $vendorId, int $vendorOrderId, string $note, bool $toCustomer, ?int $userId): void
    {
        $vo = $this->db->first('SELECT order_id FROM vendor_orders WHERE id = ? AND vendor_id = ?', [$vendorOrderId, $vendorId]);
        if ($vo === null) {
            throw new \DomainException('Order not found.');
        }
        (new OrderService($this->db))->note((int) $vo['order_id'], mb_substr_safe(trim($note), 2000), $toCustomer, $vendorOrderId, $userId);
    }

    /** Row-locked vendor order in one of the allowed states (call inside or outside a transaction). */
    private function lock(int $vendorId, int $vendorOrderId, array $allowed): array
    {
        $vo = $this->db->first('SELECT * FROM vendor_orders WHERE id = ? AND vendor_id = ? FOR UPDATE', [$vendorOrderId, $vendorId]);
        if ($vo === null) {
            throw new \DomainException('Order not found.');
        }
        if (!in_array($vo['status'], $allowed, true)) {
            throw new \DomainException('This order is ' . strtolower(self::STATUS_LABELS[$vo['status']] ?? $vo['status']) . ' and cannot be changed.');
        }
        return $vo;
    }
}
