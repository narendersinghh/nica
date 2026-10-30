<?php $this->layout('emails/layout', ['heading' => 'New order #' . $order['number']]) ?>
<p>Order from <?= e($order['email']) ?> · <?= e($order['payment_method_title'] ?: '') ?> · <strong><?= e(money((int) $order['total_cents'])) ?></strong></p>
<?= $this->insert('emails/order-items', ['items' => $items, 'showVendor' => true]) ?>
<table role="presentation" cellpadding="4" cellspacing="0" style="width:100%;font-size:13px">
    <tr style="background:#F6ECE3"><th align="left">Vendor</th><th align="right">Total</th><th align="right">Commission</th><th align="right">Vendor earning</th><th align="left">Payout</th></tr>
    <?php foreach ($vendorOrders as $vo): ?>
        <tr><td><?= e($vo['vendor_name']) ?></td><td align="right"><?= e(money((int) $vo['total_cents'])) ?></td><td align="right"><?= e(money((int) $vo['commission_cents'])) ?></td><td align="right"><?= e(money((int) $vo['vendor_earning_cents'])) ?></td><td><?= $vo['payout_mode'] === 'stripe_connect' ? 'Stripe transfer' : 'Manual' ?></td></tr>
    <?php endforeach ?>
</table>
