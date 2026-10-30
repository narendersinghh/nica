<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Services\Catalog\ProductIndexer;
use App\Services\Payments\Payments;
use App\Services\Payments\StripeException;
use App\Services\Referrals\Referrals;

/**
 * Admin-side order operations: refunds (per vendor part), cancelling unpaid
 * orders, releasing on-hold orders and completing orders for vendors.
 */
final class AdminOrders
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Refund part or all of one vendor's share of an order. The money goes back
     * through Stripe when the order was paid online here; older (imported)
     * orders are recorded and must be refunded in the Stripe dashboard.
     * @throws \DomainException
     * @return array{online: bool, refund_id: ?string}
     */
    public function refund(int $orderId, int $vendorOrderId, int $amountCents, string $reason, bool $restock, ?int $adminId): array
    {
        $order = $this->db->first('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $vo = $this->db->first('SELECT * FROM vendor_orders WHERE id = ? AND order_id = ?', [$vendorOrderId, $orderId]);
        if ($order === null || $vo === null) {
            throw new \DomainException('Order not found.');
        }
        if (!$order['paid_at'] && !in_array($order['status'], ['processing', 'on-hold', 'completed'], true)) {
            throw new \DomainException('Only paid orders can be refunded.');
        }
        // 1. Reserve the amount under row locks, so two refunds at once can never exceed what was paid.
        //    Imported orders may carry their refunds on the order only, so cap by both.
        $refundRowId = $this->db->transaction(function () use ($order, $vo, $amountCents, $reason, $adminId) {
            $o = $this->db->first('SELECT total_cents, refunded_cents FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
            $v = $this->db->first('SELECT total_cents, refunded_cents FROM vendor_orders WHERE id = ? FOR UPDATE', [$vo['id']]);
            $left = min((int) $v['total_cents'] - (int) $v['refunded_cents'], (int) $o['total_cents'] - (int) $o['refunded_cents']);
            if ($amountCents <= 0 || $amountCents > $left) {
                throw new \DomainException('You can refund up to ' . money(max(0, $left)) . ' for this vendor.');
            }
            $this->db->query('UPDATE vendor_orders SET refunded_cents = refunded_cents + ? WHERE id = ?', [$amountCents, $vo['id']]);
            $this->db->query('UPDATE orders SET refunded_cents = refunded_cents + ? WHERE id = ?', [$amountCents, $order['id']]);
            return $this->db->insert('refunds', [
                'order_id' => (int) $order['id'], 'vendor_order_id' => (int) $vo['id'], 'amount_cents' => $amountCents,
                'reason' => $reason !== '' ? mb_substr_safe($reason, 500) : null, 'status' => 'pending', 'created_by' => $adminId,
            ]);
        });

        // 2. Money back through Stripe (outside any transaction). The key is unique to this refund,
        //    so a retried request can never refund twice.
        $intentId = (string) ($order['stripe_payment_intent_id'] ?? '');
        $gateway = $intentId !== '' && $order['legacy_wp_id'] === null ? Payments::forIntent($intentId) : null;
        $refundId = null;
        if ($gateway !== null) {
            try {
                $refundId = (string) ($gateway->refund($intentId, $amountCents, 'refund_' . $refundRowId)['id'] ?? '');
            } catch (StripeException $e) {
                $this->db->transaction(function () use ($order, $vo, $amountCents, $refundRowId) {
                    $this->db->query('UPDATE vendor_orders SET refunded_cents = refunded_cents - ? WHERE id = ?', [$amountCents, $vo['id']]);
                    $this->db->query('UPDATE orders SET refunded_cents = refunded_cents - ? WHERE id = ?', [$amountCents, $order['id']]);
                    $this->db->update('refunds', ['status' => 'failed'], ['id' => $refundRowId]);
                });
                throw new \DomainException('Stripe refused the refund: ' . $e->getMessage());
            }
        }

        // 3. Record the outcome: statuses, vendor and stylist ledgers, stock, notes.
        $this->db->transaction(function () use ($order, $vo, $amountCents, $reason, $restock, $adminId, $refundId, $gateway, $refundRowId) {
            $vo = $this->db->first('SELECT * FROM vendor_orders WHERE id = ? FOR UPDATE', [$vo['id']]);
            $full = (int) $vo['refunded_cents'] >= (int) $vo['total_cents'];
            $this->db->update('refunds', ['status' => 'succeeded', 'stripe_refund_id' => $refundId ?: null], ['id' => $refundRowId]);
            if ($full) {
                $this->db->update('vendor_orders', ['status' => 'refunded'], ['id' => (int) $vo['id']]);
            }
            if ((int) $this->db->scalar("SELECT COUNT(*) FROM vendor_orders WHERE order_id = ? AND status <> 'refunded'", [$order['id']]) === 0) {
                $this->db->update('orders', ['status' => 'refunded'], ['id' => (int) $order['id']]);
            }

            // The vendor gives back their share of the refunded amount (commission is returned in proportion).
            $share = (int) $vo['total_cents'] > 0 ? (int) round($amountCents * (int) $vo['vendor_earning_cents'] / (int) $vo['total_cents']) : 0;
            $earned = (int) $this->db->scalar("SELECT COUNT(*) FROM vendor_ledger WHERE vendor_order_id = ? AND type = 'earning'", [$vo['id']]);
            if ($share > 0 && $earned) {
                $released = (bool) $this->db->scalar("SELECT 1 FROM vendor_ledger WHERE vendor_order_id = ? AND type = 'earning' AND available_at IS NOT NULL", [$vo['id']]);
                $this->db->insert('vendor_ledger', [
                    'vendor_id' => (int) $vo['vendor_id'], 'vendor_order_id' => (int) $vo['id'], 'type' => 'refund', 'amount_cents' => -$share,
                    'description' => 'Refund on order #' . $order['number'],
                    // Matches the earning: still pending if the earning is pending.
                    'available_at' => $released || $vo['stripe_transfer_id'] ? gmdate('Y-m-d H:i:s') : null,
                    'reference' => $refundId ?: null,
                ]);
            }

            (new Referrals($this->db))->refund($vo, $amountCents, $order);

            if ($restock && $full) {
                $indexer = new ProductIndexer($this->db);
                foreach ($this->db->select('SELECT * FROM order_items WHERE vendor_order_id = ? AND variant_id IS NOT NULL', [$vo['id']]) as $item) {
                    $qty = (int) $item['quantity'] - (int) $item['refunded_qty'];
                    $this->db->query("UPDATE product_variants SET stock_qty = stock_qty + ?, stock_status = IF(stock_qty > 0 AND stock_status = 'outofstock', 'instock', stock_status) WHERE id = ? AND manage_stock = 1", [$qty, $item['variant_id']]);
                    $this->db->update('order_items', ['refunded_qty' => (int) $item['quantity']], ['id' => (int) $item['id']]);
                    if ($item['product_id']) {
                        $indexer->refresh((int) $item['product_id']);
                    }
                }
            }
            $orders = new OrderService($this->db);
            $orders->note((int) $order['id'], 'Refunded ' . money($amountCents) . ($reason !== '' ? ' (' . $reason . ')' : '') . '.', true, (int) $vo['id'], $adminId);
            if ($gateway === null) {
                $orders->note((int) $order['id'], 'Recorded only: refund ' . money($amountCents) . ' to the customer in the Stripe dashboard.', false, (int) $vo['id'], $adminId);
            }
            if ($vo['stripe_transfer_id']) {
                $orders->note((int) $order['id'], 'The vendor was paid by Stripe transfer; their share is deducted from their marketplace balance.', false, (int) $vo['id'], $adminId);
            }
        });
        \App\Services\Cache\PageCache::flush();
        return ['online' => $gateway !== null, 'refund_id' => $refundId];
    }

    /** Cancel an order that was never paid: stock and points go back. */
    public function cancel(int $orderId, ?int $adminId): void
    {
        $this->db->transaction(function () use ($orderId, $adminId) {
            $order = $this->db->first('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($order === null || $order['paid_at'] || !in_array($order['status'], ['pending', 'on-hold', 'failed'], true)) {
                throw new \DomainException('Paid orders must be refunded instead of cancelled.');
            }
            $this->db->update('orders', ['status' => 'cancelled'], ['id' => $orderId]);
            $this->db->query("UPDATE vendor_orders SET status = 'cancelled' WHERE order_id = ?", [$orderId]);
            if ((int) $order['stock_reduced']) {
                foreach ($this->db->select('SELECT variant_id, quantity FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL', [$orderId]) as $i) {
                    $this->db->query("UPDATE product_variants SET stock_qty = stock_qty + ?, stock_status = IF(stock_status = 'outofstock', 'instock', stock_status) WHERE id = ? AND manage_stock = 1", [$i['quantity'], $i['variant_id']]);
                }
                $this->db->update('orders', ['stock_reduced' => 0], ['id' => $orderId]);
            }
            $orders = new OrderService($this->db);
            $orders->restorePoints($orderId);
            $orders->note($orderId, 'Order cancelled by staff.', false, null, $adminId);
        });
        $intentId = (string) $this->db->scalar('SELECT stripe_payment_intent_id FROM orders WHERE id = ?', [$orderId]);
        if ($intentId !== '' && ($gateway = Payments::forIntent($intentId))) {
            $gateway->cancelIntent($intentId);
        }
    }

    /** On-hold (e.g. stock or amount check) and the team has verified it: release to vendors. */
    public function release(int $orderId, ?int $adminId): void
    {
        $order = $this->db->first('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null || $order['status'] !== 'on-hold' || !$order['paid_at']) {
            throw new \DomainException('Only paid orders on hold can be released.');
        }
        $this->db->update('orders', ['status' => 'processing'], ['id' => $orderId]);
        $this->db->query("UPDATE vendor_orders SET status = 'processing' WHERE order_id = ? AND status = 'on-hold'", [$orderId]);
        (new OrderService($this->db))->note($orderId, 'Checked by staff and released to the vendors.', false, null, $adminId);
    }
}
