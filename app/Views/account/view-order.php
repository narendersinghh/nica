<?php $this->layout('account/layout');
$labels = ['processing' => 'Processing', 'on-hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed', 'pending' => 'Pending payment', 'shipped' => 'Shipped'];
$billing = json_decode($order['billing_address'], true) ?: [];
$shipping = json_decode($order['shipping_address'], true) ?: [];
?>
<p>Order <mark>#<?= e($order['number']) ?></mark> was placed on <mark><?= e(date('F j, Y', strtotime($order['created_at']))) ?></mark> and is currently <mark><?= e($labels[$order['status']] ?? $order['status']) ?></mark>.</p>
<?php foreach ($vendorOrders as $vo): if ($vo['tracking_number']): ?>
    <div class="notice"><?= icon('bag') ?> <?= e($vo['vendor_name']) ?> shipped your items: <?= e($vo['tracking_carrier'] ?: 'Tracking') ?> <?= $vo['tracking_url'] ? '<a href="' . e($vo['tracking_url']) . '" target="_blank" rel="noopener">' . e($vo['tracking_number']) . '</a>' : e($vo['tracking_number']) ?></div>
<?php endif; endforeach ?>
<?php foreach ($notes as $n): ?><div class="notice"><?= e(date('M j, Y', strtotime($n['created_at']))) ?>: <?= e($n['note']) ?></div><?php endforeach ?>
<h2 class="account-subtitle">Order details</h2>
<table class="review-table">
    <thead><tr><th scope="col">Product</th><th scope="col">Total</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
        <tr><td><?= $i['product_slug'] ? '<a href="' . e(link_to('/product/' . $i['product_slug'] . '/')) . '">' . e($i['name']) . '</a>' : e($i['name']) ?> <strong>× <?= (int) $i['quantity'] ?></strong>
            <?php if ($i['variant_label']): ?><span><?= e($i['variant_label']) ?></span><?php endif ?><span>Sold by <?= e($i['vendor_name']) ?></span></td>
            <td><?= e(money((int) $i['subtotal_cents'])) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot>
        <tr><th scope="row">Subtotal</th><td><?= e(money((int) $order['subtotal_cents'])) ?></td></tr>
        <?php if ((int) $order['discount_cents']): ?><tr><th scope="row">Discount</th><td>−<?= e(money((int) $order['discount_cents'])) ?></td></tr><?php endif ?>
        <tr><th scope="row">Shipping</th><td><?= (int) $order['shipping_cents'] ? e(money((int) $order['shipping_cents'])) : 'Free shipping' ?></td></tr>
        <?php if ((int) $order['tax_cents']): ?><tr><th scope="row">Tax</th><td><?= e(money((int) $order['tax_cents'])) ?></td></tr><?php endif ?>
        <?php if ((int) $order['points_discount_cents']): ?><tr><th scope="row">Points (<?= (int) $order['points_redeemed'] ?>)</th><td>−<?= e(money((int) $order['points_discount_cents'])) ?></td></tr><?php endif ?>
        <?php if ($order['payment_method_title']): ?><tr><th scope="row">Payment method</th><td><?= e($order['payment_method_title']) ?></td></tr><?php endif ?>
        <tr class="totals-table__total"><th scope="row">Total</th><td><strong><?= e(money((int) $order['total_cents'])) ?></strong></td></tr>
        <?php foreach ($refunds as $r): ?><tr><th scope="row">Refunded <?= e(date('M j', strtotime($r['created_at']))) ?></th><td>−<?= e(money((int) $r['amount_cents'])) ?></td></tr><?php endforeach ?>
    </tfoot>
</table>
<?php if ($order['customer_note']): ?><p><strong>Note:</strong> <?= e($order['customer_note']) ?></p><?php endif ?>
<div class="address-grid">
    <div><h3>Billing address</h3><address><?= $this->insert('account/address', ['a' => $billing]) ?><br><?= e($order['email']) ?></address></div>
    <div><h3>Shipping address</h3><address><?= $this->insert('account/address', ['a' => $shipping]) ?></address></div>
</div>
