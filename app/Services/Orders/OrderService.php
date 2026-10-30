<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Services\Catalog\ProductIndexer;
use App\Services\Mail\Mailer;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use App\Services\Points;
use App\Services\Referrals\Referrals;
use App\Services\Settings;

/**
 * Order lifecycle: create (pending) from a priced cart, mark paid (idempotent,
 * row-locked), mark failed. Money for each vendor goes into the vendor ledger;
 * vendors connected to Stripe are paid by transfer right away.
 */
final class OrderService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array $summary Cart::summary() result
     * @param array $customer email, billing{}, shipping{}, note
     */
    public function createFromCart(array $summary, array $customer, ?int $userId, string $ip, string $userAgent, ?string $referral = null): array
    {
        if (!$summary['packages']) {
            throw new \DomainException('Your cart is empty.');
        }
        $vendors = [];
        foreach ($this->db->select('SELECT id, stripe_account_id, stripe_charges_enabled, stripe_payouts_enabled FROM vendors WHERE id IN (' . implode(',', array_map(static fn ($p) => (int) $p['vendor_id'], $summary['packages'])) . ')') as $v) {
            $vendors[(int) $v['id']] = $v;
        }

        return $this->db->transaction(function () use ($summary, $customer, $userId, $ip, $userAgent, $referral, $vendors) {
            $t = $summary['totals'];
            $orderId = $this->db->insert('orders', [
                'number' => 'tmp-' . bin2hex(random_bytes(8)),
                'access_key' => bin2hex(random_bytes(20)),
                'user_id' => $userId,
                'email' => strtolower($customer['email']),
                'status' => 'pending',
                'currency' => (string) Settings::get('commerce.currency', 'USD'),
                'subtotal_cents' => $t['subtotal_cents'],
                'discount_cents' => $t['discount_cents'],
                'shipping_cents' => $t['shipping_cents'],
                'tax_cents' => $t['tax_cents'],
                'points_discount_cents' => $t['points_discount_cents'] ?? 0,
                'points_redeemed' => $t['points_used'] ?? 0,
                'total_cents' => $t['total_cents'],
                'billing_address' => json_encode($customer['billing'], JSON_UNESCAPED_UNICODE),
                'shipping_address' => json_encode($customer['shipping'], JSON_UNESCAPED_UNICODE),
                'customer_note' => $customer['note'] ?: null,
                'coupon_codes' => $summary['coupons'] ? json_encode(array_map(static fn ($c) => $c['coupon']['code'], $summary['coupons'])) : null,
                'referral_code' => $referral,
                'ip' => $ip,
                'user_agent' => mb_substr_safe($userAgent, 255),
            ]);
            $number = (string) ((int) Settings::get('commerce.order_number_start', 6000) + $orderId);
            $this->db->update('orders', ['number' => $number], ['id' => $orderId]);

            // Points are reserved now and given back if the order is never paid.
            $points = (int) ($t['points_used'] ?? 0);
            if ($points > 0 && (!$userId || !(new Points($this->db))->add($userId, -$points, 'redeem', $orderId, 'Order #' . $number))) {
                throw new \DomainException('You no longer have enough points. Please review your order.');
            }

            foreach ($summary['packages'] as $pkg) {
                $v = $vendors[(int) $pkg['vendor_id']] ?? [];
                $connect = !empty($v['stripe_account_id']) && (int) ($v['stripe_charges_enabled'] ?? 0) === 1 && (int) ($v['stripe_payouts_enabled'] ?? 0) === 1;
                $vendorOrderId = $this->db->insert('vendor_orders', [
                    'order_id' => $orderId,
                    'vendor_id' => (int) $pkg['vendor_id'],
                    'status' => 'pending',
                    'subtotal_cents' => $pkg['subtotal_cents'],
                    'discount_cents' => $pkg['discount_cents'],
                    'vendor_discount_cents' => $pkg['vendor_discount_cents'],
                    'shipping_cents' => $pkg['shipping_cents'],
                    'shipping_method' => $pkg['shipping_title'],
                    'tax_cents' => $pkg['tax_cents'],
                    'total_cents' => $pkg['total_cents'],
                    'commission_rate' => $pkg['commission_rate'],
                    'commission_cents' => $pkg['commission_cents'],
                    'vendor_earning_cents' => $pkg['vendor_earning_cents'],
                    'payout_mode' => $connect ? 'stripe_connect' : 'manual',
                ]);
                foreach ($pkg['lines'] as $l) {
                    $this->db->insert('order_items', [
                        'order_id' => $orderId,
                        'vendor_order_id' => $vendorOrderId,
                        'product_id' => $l['product_id'],
                        'variant_id' => $l['variant_id'],
                        'name' => mb_substr_safe($l['name'], 255),
                        'variant_label' => $l['variant_label'],
                        'sku' => $l['sku'],
                        'image_media_id' => $l['image_media_id'],
                        'quantity' => $l['quantity'],
                        'unit_price_cents' => $l['unit_cents'],
                        'subtotal_cents' => $l['subtotal_cents'],
                        'discount_cents' => $l['discount_cents'],
                        'tax_cents' => $l['tax_cents'],
                        'total_cents' => $l['total_cents'],
                    ]);
                }
            }
            $this->note($orderId, 'Order created, awaiting payment.');
            return $this->find($orderId);
        });
    }

    public function attachIntent(int $orderId, string $intentId, string $gateway): void
    {
        $this->db->update('orders', ['stripe_payment_intent_id' => $intentId, 'payment_method' => $gateway], ['id' => $orderId]);
    }

    /**
     * Confirm payment. Safe to call many times (return page + webhook): only the
     * first call that sees a pending order does the work.
     * @param array $intent PaymentGateway::fetchIntent() result
     * @return string order status after the call
     */
    public function markPaid(int $orderId, array $intent, ?PaymentGateway $gateway = null): string
    {
        $transfers = [];
        $status = $this->db->transaction(function () use ($orderId, $intent, &$transfers) {
            $order = $this->db->first('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if ($order === null) {
                throw new \DomainException('Order not found.');
            }
            if ($order['status'] === 'cancelled' && !$order['paid_at']) {
                // Paid after we cancelled it (the intent could not be cancelled in time): never lose this silently.
                $this->note($orderId, 'Payment ' . $intent['id'] . ' arrived after this order was cancelled. Refund it in Stripe, or contact the customer.');
                app()->logger()->warning('Payment for a cancelled order', ['order' => $order['number'], 'intent' => $intent['id']]);
                (new Mailer())->queue((string) Settings::get('site.contact.email'), '[' . config('app.name') . '] Payment received for cancelled order #' . $order['number'],
                    '<p>Stripe payment ' . e((string) $intent['id']) . ' (' . e(money((int) $intent['amount'])) . ') arrived after order #' . e($order['number']) . ' was cancelled. Please refund it in the Stripe dashboard or contact the customer.</p>');
                return 'cancelled';
            }
            if (!in_array($order['status'], ['pending', 'failed'], true)) {
                return $order['status']; // already handled
            }
            // The amount charged must be exactly what the order says, for this order.
            if ((int) $intent['amount'] !== (int) $order['total_cents'] || strtoupper($intent['currency']) !== strtoupper($order['currency'])
                || (string) ($intent['metadata']['order_id'] ?? '') !== (string) $order['id']) {
                $this->db->update('orders', ['status' => 'on-hold'], ['id' => $orderId]);
                $this->note($orderId, 'Payment amount mismatch (' . money((int) $intent['amount']) . ' paid). Please review before shipping.');
                return 'on-hold';
            }

            $now = gmdate('Y-m-d H:i:s');
            $newStatus = $this->reduceStock($orderId) ? 'processing' : 'on-hold';
            // Points used on this order were given back earlier (e.g. by a cancellation): take them again.
            if ((int) $order['points_redeemed'] > 0 && $order['user_id']
                && (int) $this->db->scalar("SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE order_id = ? AND reason IN ('redeem','restore')", [$orderId]) > -(int) $order['points_redeemed']) {
                if (!(new Points($this->db))->add((int) $order['user_id'], -(int) $order['points_redeemed'], 'redeem', $orderId, 'Order #' . $order['number'] . ' paid')) {
                    $newStatus = 'on-hold';
                    $this->note($orderId, 'The points discount on this order is no longer covered by the customer\'s balance. Check before shipping.');
                }
            }
            $this->db->update('orders', [
                'status' => $newStatus,
                'paid_at' => $now,
                'stripe_payment_intent_id' => $intent['id'],
                'stripe_charge_id' => $intent['charge_id'],
                'payment_method_title' => self::methodTitle($intent['method_type'] ?? null),
                'stock_reduced' => 1,
            ], ['id' => $orderId]);
            $this->db->query("UPDATE vendor_orders SET status = ? WHERE order_id = ?", [$newStatus, $orderId]);
            $this->note($orderId, 'Payment received (' . self::methodTitle($intent['method_type'] ?? null) . ', ' . $intent['id'] . ').');

            $this->recordCoupons($order);

            // Purchase points (off on the live site: points_per_dollar_spent = 0).
            $perDollar = (int) Settings::get('points.points_per_dollar_spent', 0);
            if ($perDollar > 0 && $order['user_id'] && Points::enabled()) {
                $earned = intdiv(((int) $order['total_cents'] - (int) $order['tax_cents'] - (int) $order['shipping_cents']) * $perDollar, 100);
                (new Points($this->db))->add((int) $order['user_id'], max(0, $earned), 'order', $orderId, 'Order #' . $order['number']);
            }

            // Vendor earnings: withdrawable once the vendor order is completed (Dokan rule).
            foreach ($this->db->select('SELECT * FROM vendor_orders WHERE order_id = ?', [$orderId]) as $vo) {
                $this->db->insert('vendor_ledger', [
                    'vendor_id' => (int) $vo['vendor_id'],
                    'vendor_order_id' => (int) $vo['id'],
                    'type' => 'earning',
                    'amount_cents' => (int) $vo['vendor_earning_cents'],
                    'description' => 'Order #' . $order['number'],
                    'available_at' => null,
                ]);
                if ($vo['payout_mode'] === 'stripe_connect') {
                    $transfers[] = $vo;
                }
            }
            (new Referrals($this->db))->recordForOrder($order);
            return $newStatus;
        });

        if ($status !== 'pending') {
            \App\Services\Cache\PageCache::flush(); // stock and "in stock" badges changed
        }
        // Connect transfers happen after commit (network calls never inside a DB transaction).
        if ($transfers && $gateway !== null) {
            $order = $this->find($orderId);
            foreach ($transfers as $vo) {
                $this->transferToVendor($order, $vo, $gateway, $intent['charge_id'] ?? null);
            }
        }
        if (in_array($status, ['processing', 'on-hold'], true)) {
            \App\Services\Integrations::event('purchase', $this->find($orderId) ?? []);
            $this->sendOrderEmails($orderId);
        }
        return $status;
    }

    public function markFailed(int $orderId, string $reason): void
    {
        $order = $this->db->first('SELECT status FROM orders WHERE id = ?', [$orderId]);
        if ($order && $order['status'] === 'pending') {
            $this->db->update('orders', ['status' => 'failed'], ['id' => $orderId]);
            $this->db->query("UPDATE vendor_orders SET status = 'failed' WHERE order_id = ?", [$orderId]);
            // Points stay reserved: the customer can retry with another card on the same order.
            // They come back only when the order is cancelled for good (cancel()).
            $this->note($orderId, 'Payment failed: ' . $reason);
        }
    }

    /**
     * Cancel an unpaid order for good: stock hold ends, points go back and the
     * payment can no longer be completed (the PaymentIntent is cancelled).
     */
    public function cancel(int $orderId, string $note, ?int $userId = null): void
    {
        $order = $this->db->first('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if ($order === null || $order['paid_at'] || !in_array($order['status'], ['pending', 'failed', 'on-hold'], true)) {
            return;
        }
        $this->db->update('orders', ['status' => 'cancelled'], ['id' => $orderId]);
        $this->db->query("UPDATE vendor_orders SET status = 'cancelled' WHERE order_id = ?", [$orderId]);
        $this->note($orderId, $note, false, null, $userId);
        $this->restorePoints($orderId);
        if ($order['stripe_payment_intent_id'] && ($gateway = Payments::forIntent((string) $order['stripe_payment_intent_id']))) {
            $gateway->cancelIntent((string) $order['stripe_payment_intent_id']);
        }
    }

    /** Pending orders whose payment never completed are cancelled after the stock hold time. */
    public function cancelAbandoned(): int
    {
        $minutes = (int) Settings::get('commerce.stock_hold_minutes', 60);
        $ids = array_column($this->db->select("SELECT id FROM orders WHERE status IN ('pending','failed') AND paid_at IS NULL AND created_at < (UTC_TIMESTAMP() - INTERVAL ? MINUTE)", [$minutes]), 'id');
        foreach ($ids as $id) {
            $this->cancel((int) $id, 'Unpaid order cancelled after ' . $minutes . ' minutes.');
        }
        return count($ids);
    }

    /** Give back points reserved by an order that will never be paid (once). */
    public function restorePoints(int $orderId): void
    {
        $order = $this->db->first('SELECT user_id, number, points_redeemed FROM orders WHERE id = ?', [$orderId]);
        if (!$order || !$order['user_id'] || (int) $order['points_redeemed'] <= 0) {
            return;
        }
        if (!$this->db->scalar("SELECT 1 FROM points_ledger WHERE order_id = ? AND reason = 'restore'", [$orderId])) {
            (new Points($this->db))->add((int) $order['user_id'], (int) $order['points_redeemed'], 'restore', $orderId, 'Order #' . $order['number'] . ' not completed');
        }
    }

    public function find(int $orderId): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE id = ?', [$orderId]);
    }

    public function findByNumberAndKey(string $number, string $key): ?array
    {
        $order = $this->db->first('SELECT * FROM orders WHERE number = ?', [$number]);
        return $order && $key !== '' && hash_equals($order['access_key'], $key) ? $order : null;
    }

    public function items(int $orderId): array
    {
        return $this->db->select(
            'SELECT oi.*, vo.vendor_id, v.store_name AS vendor_name, p.slug AS product_slug FROM order_items oi
             JOIN vendor_orders vo ON vo.id = oi.vendor_order_id JOIN vendors v ON v.id = vo.vendor_id
             LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ? ORDER BY vo.id, oi.id',
            [$orderId],
        );
    }

    public function vendorOrders(int $orderId): array
    {
        return $this->db->select('SELECT vo.*, v.store_name AS vendor_name FROM vendor_orders vo JOIN vendors v ON v.id = vo.vendor_id WHERE vo.order_id = ? ORDER BY vo.id', [$orderId]);
    }

    public function note(int $orderId, string $note, bool $customerVisible = false, ?int $vendorOrderId = null, ?int $userId = null): void
    {
        $this->db->insert('order_notes', ['order_id' => $orderId, 'vendor_order_id' => $vendorOrderId, 'user_id' => $userId, 'note' => $note, 'is_customer_visible' => $customerVisible ? 1 : 0]);
    }

    /* -------------------------------------------------------------- private */

    /** Atomic per-variant stock decrement; false when something oversold (order goes on hold). */
    private function reduceStock(int $orderId): bool
    {
        $ok = true;
        $touched = [];
        foreach ($this->db->select('SELECT variant_id, product_id, quantity, name FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL', [$orderId]) as $item) {
            $variant = $this->db->first('SELECT manage_stock, stock_qty, stock_status FROM product_variants WHERE id = ? FOR UPDATE', [$item['variant_id']]);
            if ($variant && (int) $variant['manage_stock'] === 1) {
                $updated = $this->db->query(
                    "UPDATE product_variants SET stock_qty = stock_qty - ?, stock_status = IF(stock_qty - ? <= 0 AND stock_status <> 'onbackorder', 'outofstock', stock_status)
                     WHERE id = ? AND (stock_qty >= ? OR stock_status = 'onbackorder')",
                    [$item['quantity'], $item['quantity'], $item['variant_id'], $item['quantity']],
                )->rowCount();
                if ($updated === 0) {
                    $ok = false;
                    $this->note($orderId, 'Not enough stock for “' . $item['name'] . '” when payment arrived. Check stock or refund this item.');
                }
            }
            $this->db->query('UPDATE products SET sales_count = sales_count + ? WHERE id = ?', [$item['quantity'], $item['product_id']]);
            $touched[(int) $item['product_id']] = true;
        }
        $indexer = new ProductIndexer($this->db);
        foreach (array_keys($touched) as $productId) {
            $indexer->refresh($productId);
        }
        return $ok;
    }

    private function recordCoupons(array $order): void
    {
        $codes = json_decode((string) $order['coupon_codes'], true) ?: [];
        foreach ($codes as $code) {
            $coupon = $this->db->first('SELECT id FROM coupons WHERE code = ?', [$code]);
            if ($coupon) {
                $this->db->query(
                    'INSERT IGNORE INTO coupon_usages (coupon_id, order_id, user_id, email) VALUES (?, ?, ?, ?)',
                    [$coupon['id'], $order['id'], $order['user_id'], $order['email']],
                );
                $this->db->query('UPDATE coupons SET usage_count = usage_count + 1 WHERE id = ?', [$coupon['id']]);
            }
        }
    }

    private function transferToVendor(array $order, array $vo, PaymentGateway $gateway, ?string $chargeId): void
    {
        $account = (string) $this->db->scalar('SELECT stripe_account_id FROM vendors WHERE id = ?', [$vo['vendor_id']]);
        $amount = (int) $vo['vendor_earning_cents'];
        if ($account === '' || $amount <= 0 || $vo['stripe_transfer_id']) {
            return;
        }
        try {
            $transferId = $gateway->transfer($amount, $account, 'order_' . $order['number'], $chargeId, 'transfer_vo_' . $vo['id']);
            $this->db->transaction(function () use ($vo, $transferId, $amount, $order) {
                $this->db->update('vendor_orders', ['stripe_transfer_id' => $transferId], ['id' => (int) $vo['id']]);
                $this->db->insert('vendor_ledger', [
                    'vendor_id' => (int) $vo['vendor_id'], 'vendor_order_id' => (int) $vo['id'], 'type' => 'transfer',
                    'amount_cents' => -$amount, 'description' => 'Paid to Stripe account for order #' . $order['number'],
                    'available_at' => gmdate('Y-m-d H:i:s'), 'reference' => $transferId,
                ]);
                // The earning was paid out immediately, so it is "available" (and already withdrawn).
                $this->db->query("UPDATE vendor_ledger SET available_at = UTC_TIMESTAMP() WHERE vendor_order_id = ? AND type = 'earning'", [$vo['id']]);
            });
        } catch (\Throwable $e) {
            // Falls back to a manual payout; the earning stays in the ledger.
            $this->db->update('vendor_orders', ['payout_mode' => 'manual'], ['id' => (int) $vo['id']]);
            $this->note((int) $order['id'], 'Stripe transfer to vendor failed (' . $e->getMessage() . '); switched to manual payout.', false, (int) $vo['id']);
            app()->logger()->warning('Stripe transfer failed', ['vendor_order' => $vo['id'], 'error' => $e->getMessage()]);
        }
    }

    private function sendOrderEmails(int $orderId): void
    {
        $order = $this->find($orderId);
        $items = $this->items($orderId);
        $view = app()->view();
        $mailer = new Mailer();
        $mailer->queue($order['email'], 'Your ' . config('app.name') . ' order #' . $order['number'] . ' has been received!',
            $view->render('emails/order-customer', ['order' => $order, 'items' => $items, 'vendorOrders' => $this->vendorOrders($orderId)]));

        foreach ($this->vendorOrders($orderId) as $vo) {
            $vendorEmail = $this->db->scalar('SELECT u.email FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = ?', [$vo['vendor_id']]);
            if ($vendorEmail) {
                $mailer->queue((string) $vendorEmail, '[' . config('app.name') . '] New order #' . $order['number'],
                    $view->render('emails/order-vendor', ['order' => $order, 'vo' => $vo, 'items' => array_values(array_filter($items, static fn ($i) => (int) $i['vendor_order_id'] === (int) $vo['id']))]));
            }
        }
        $admin = (string) \App\Services\Settings::get('site.contact.email');
        $mailer->queue($admin, '[' . config('app.name') . '] New order #' . $order['number'] . ' (' . money((int) $order['total_cents']) . ')',
            $view->render('emails/order-admin', ['order' => $order, 'items' => $items, 'vendorOrders' => $this->vendorOrders($orderId)]));
    }

    public static function methodTitle(?string $type): string
    {
        return match ($type) {
            'card' => 'Credit / debit card',
            'link' => 'Link',
            'klarna' => 'Klarna',
            'afterpay_clearpay' => 'Afterpay',
            'affirm' => 'Affirm',
            'cashapp' => 'Cash App Pay',
            'us_bank_account' => 'Bank account',
            null, '' => 'Card',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
