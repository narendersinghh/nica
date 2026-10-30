<?php $this->layout('emails/layout', ['heading' => 'Thank you for your order']) ?>
<p>Hi <?= e((json_decode($order['billing_address'], true)['first_name'] ?? '') ?: 'there') ?>,</p>
<p>We've received your order <strong>#<?= e($order['number']) ?></strong> and our vendors are preparing it. Here's a summary:</p>
<?= $this->insert('emails/order-items', ['items' => $items, 'showVendor' => count($vendorOrders) > 1]) ?>
<table role="presentation" cellpadding="4" cellspacing="0" style="width:100%;font-size:14px">
    <tr><td>Subtotal</td><td align="right"><?= e(money((int) $order['subtotal_cents'])) ?></td></tr>
    <?php if ((int) $order['discount_cents']): ?><tr><td>Discount</td><td align="right">−<?= e(money((int) $order['discount_cents'])) ?></td></tr><?php endif ?>
    <tr><td>Shipping</td><td align="right"><?= (int) $order['shipping_cents'] ? e(money((int) $order['shipping_cents'])) : 'Free' ?></td></tr>
    <?php if ((int) $order['tax_cents']): ?><tr><td>Tax</td><td align="right"><?= e(money((int) $order['tax_cents'])) ?></td></tr><?php endif ?>
    <tr><td><strong>Total</strong></td><td align="right"><strong><?= e(money((int) $order['total_cents'])) ?></strong></td></tr>
</table>
<p style="margin-top:20px"><a href="<?= e(url('/thank-you/', ['order' => $order['number'], 'key' => $order['access_key']])) ?>" style="display:inline-block;background:#2C211C;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none">View your order</a></p>
<p style="color:#777;font-size:13px">Questions? Reply to this email or contact <?= e(\App\Services\Settings::get('site.contact.email')) ?>.</p>
