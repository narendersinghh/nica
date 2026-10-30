<?php
/** Thank-you page: the order just placed (needs ?order=…&key=… so nobody can view others' orders). */
$orders = new App\Services\Orders\OrderService(app()->db());
$order = $orders->findByNumberAndKey((string) app()->request()->query('order', ''), (string) app()->request()->query('key', ''));
if ($order === null) {
    return;
}
$items = $orders->items((int) $order['id']);
$vendorOrders = $orders->vendorOrders((int) $order['id']);
$billing = json_decode($order['billing_address'], true) ?: [];
$shipping = json_decode($order['shipping_address'], true) ?: [];
$states = config('us_states');
$addr = static fn (array $a) => implode('<br>', array_map('e', array_filter([
    trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')), $a['address_1'] ?? '', $a['address_2'] ?? '',
    trim(($a['city'] ?? '') . ', ' . ($states[$a['state'] ?? ''] ?? ($a['state'] ?? '')) . ' ' . ($a['postcode'] ?? ''), ', '), 'United States (US)',
])));
?>
<?php if (App\Services\Integrations::anyTracking() && $order['paid_at']): ?>
<script type="application/json" id="analytics-data"><?= json_encode(['event' => 'purchase', 'transaction_id' => $order['number'], 'value' => round(((int) $order['total_cents']) / 100, 2), 'tax' => round(((int) $order['tax_cents']) / 100, 2), 'shipping' => round(((int) $order['shipping_cents']) / 100, 2), 'currency' => $order['currency'],
    'items' => array_map(static fn ($i) => ['item_id' => (string) ($i['sku'] ?: 'nica-' . $i['variant_id']), 'item_name' => $i['name'], 'item_variant' => (string) $i['variant_label'], 'price' => round((int) $i['unit_price_cents'] / 100, 2), 'quantity' => (int) $i['quantity']], $items)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif ?>
<section class="section-pad section--soft">
    <div class="container order-received">
        <?php if ($order['status'] === 'on-hold' && !$order['paid_at']): ?>
            <div class="notice">Your payment is being processed. We'll email you as soon as it's confirmed.</div>
        <?php elseif (in_array($order['status'], ['failed', 'cancelled', 'pending'], true)): ?>
            <div class="notice notice--error">This order has not been paid. <a href="<?= e(link_to('/checkout/')) ?>">Return to checkout</a></div>
        <?php endif ?>
        <ul class="order-overview">
            <li>Order number:<strong><?= e($order['number']) ?></strong></li>
            <li>Date:<strong><?= e(date('F j, Y', strtotime($order['created_at']))) ?></strong></li>
            <li>Email:<strong><?= e($order['email']) ?></strong></li>
            <li>Total:<strong><?= e(money((int) $order['total_cents'])) ?></strong></li>
            <?php if ($order['payment_method_title']): ?><li>Payment method:<strong><?= e($order['payment_method_title']) ?></strong></li><?php endif ?>
        </ul>
        <div class="order-received__grid">
            <div class="cart-panel">
                <h2>Order details</h2>
                <table class="review-table">
                    <thead><tr><th scope="col">Product</th><th scope="col">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr><td><?= $i['product_slug'] ? '<a href="' . e(link_to('/product/' . $i['product_slug'] . '/')) . '">' . e($i['name']) . '</a>' : e($i['name']) ?>
                            <?php if ($i['variant_label']): ?><span><?= e($i['variant_label']) ?></span><?php endif ?>
                            <strong>× <?= (int) $i['quantity'] ?></strong><span>Sold by <?= e($i['vendor_name']) ?></span></td>
                            <td><?= e(money((int) $i['subtotal_cents'])) ?></td></tr>
                    <?php endforeach ?>
                    </tbody>
                    <tfoot>
                        <tr><th scope="row">Subtotal</th><td><?= e(money((int) $order['subtotal_cents'])) ?></td></tr>
                        <?php if ((int) $order['discount_cents']): ?><tr><th scope="row">Discount</th><td>−<?= e(money((int) $order['discount_cents'])) ?></td></tr><?php endif ?>
                        <?php foreach ($vendorOrders as $vo): ?><tr><th scope="row">Shipping<?= count($vendorOrders) > 1 ? ' (' . e($vo['vendor_name']) . ')' : '' ?></th><td><?= e($vo['shipping_method']) ?><?= (int) $vo['shipping_cents'] ? ': ' . e(money((int) $vo['shipping_cents'])) : '' ?></td></tr><?php endforeach ?>
                        <?php if ((int) $order['tax_cents']): ?><tr><th scope="row">Tax</th><td><?= e(money((int) $order['tax_cents'])) ?></td></tr><?php endif ?>
                        <tr class="totals-table__total"><th scope="row">Total</th><td><strong><?= e(money((int) $order['total_cents'])) ?></strong></td></tr>
                    </tfoot>
                </table>
                <?php if ($order['customer_note']): ?><p><strong>Note:</strong> <?= e($order['customer_note']) ?></p><?php endif ?>
            </div>
            <div class="order-received__addresses">
                <div class="cart-panel"><h3>Billing address</h3><address><?= $addr($billing) ?><?php if (!empty($billing['phone'])): ?><br><?= e($billing['phone']) ?><?php endif ?><br><?= e($order['email']) ?></address></div>
                <div class="cart-panel"><h3>Shipping address</h3><address><?= $addr($shipping) ?></address></div>
            </div>
        </div>
        <div class="order-received__actions">
            <a class="btn btn--dark" href="<?= e(link_to('/shop/')) ?>">Continue shopping <?= icon('arrow-right') ?></a>
        </div>
    </div>
</section>
