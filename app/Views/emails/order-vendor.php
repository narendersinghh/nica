<?php $this->layout('emails/layout', ['heading' => 'New order #' . $order['number']]) ?>
<p>You have a new order to fulfil. Please ship it and add the tracking number in your dashboard.</p>
<?= $this->insert('emails/order-items', ['items' => $items]) ?>
<table role="presentation" cellpadding="4" cellspacing="0" style="width:100%;font-size:14px">
    <tr><td>Shipping</td><td align="right"><?= e($vo['shipping_method']) ?> <?= (int) $vo['shipping_cents'] ? e(money((int) $vo['shipping_cents'])) : '' ?></td></tr>
    <tr><td>Commission (<?= e(rtrim(rtrim($vo['commission_rate'], '0'), '.')) ?>%)</td><td align="right">−<?= e(money((int) $vo['commission_cents'])) ?></td></tr>
    <tr><td><strong>Your earning</strong></td><td align="right"><strong><?= e(money((int) $vo['vendor_earning_cents'])) ?></strong></td></tr>
</table>
<?php $s = json_decode($order['shipping_address'], true) ?: []; ?>
<p><strong>Ship to:</strong><br><?= e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?><br><?= e($s['address_1'] ?? '') ?> <?= e($s['address_2'] ?? '') ?><br><?= e(($s['city'] ?? '') . ', ' . ($s['state'] ?? '') . ' ' . ($s['postcode'] ?? '')) ?></p>
<p><a href="<?= e(url('/dashboard/orders/')) ?>" style="color:#C3A36A">Open your vendor dashboard</a></p>
