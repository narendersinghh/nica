<?php
$order = $vo['order'];
$ship = json_decode($order['shipping_address'], true) ?: [];
$bill = json_decode($order['billing_address'], true) ?: [];
$addr = json_decode((string) $vendor['address'], true) ?: [];
$titleText = ($invoice ? 'Invoice' : 'Packing slip') . ' #' . $order['number'];
?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($titleText) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/panel.css')) ?>">
</head>
<body class="slip">
<div class="slip__page">
    <div class="slip__actions no-print"><button type="button" class="btn" data-print>Print</button></div>
    <header class="slip__head">
        <div>
            <h1><?= e($vendor['store_name']) ?></h1>
            <p class="muted">Sold on <?= e(config('app.name')) ?><br>
            <?php if ($addr): ?><?= e(implode(', ', array_filter([$addr['street_1'] ?? '', $addr['city'] ?? '', trim(($addr['state'] ?? '') . ' ' . ($addr['zip'] ?? ''))]))) ?><br><?php endif ?>
            <?= e($vendor['public_email'] ?? '') ?></p>
        </div>
        <div class="right">
            <h2><?= $invoice ? 'Invoice' : 'Packing slip' ?></h2>
            <p>Order #<?= e($order['number']) ?><br><?= e(gmdate('F j, Y', strtotime($order['created_at']))) ?><?php if ($invoice && $order['payment_method_title']): ?><br><?= e($order['payment_method_title']) ?><?php endif ?></p>
        </div>
    </header>
    <div class="slip__addresses">
        <div><h3>Ship to</h3><address class="address"><?= e(trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? ''))) ?><br><?= e($ship['address_1'] ?? '') ?><?= !empty($ship['address_2']) ? '<br>' . e($ship['address_2']) : '' ?><br><?= e(($ship['city'] ?? '') . ', ' . ($ship['state'] ?? '') . ' ' . ($ship['postcode'] ?? '')) ?></address></div>
        <?php if ($invoice): ?><div><h3>Bill to</h3><address class="address"><?= e(trim(($bill['first_name'] ?? '') . ' ' . ($bill['last_name'] ?? ''))) ?><br><?= e($bill['address_1'] ?? '') ?><br><?= e(($bill['city'] ?? '') . ', ' . ($bill['state'] ?? '') . ' ' . ($bill['postcode'] ?? '')) ?><br><?= e($order['email']) ?></address></div><?php endif ?>
    </div>
    <table class="table">
        <thead><tr><th>Item</th><th>SKU</th><th class="right">Qty</th><?php if ($invoice): ?><th class="right">Price</th><th class="right">Total</th><?php endif ?></tr></thead>
        <tbody>
        <?php foreach ($vo['items'] as $it): ?>
            <tr><td><?= e($it['name']) ?><?= $it['variant_label'] ? '<br><span class="muted small">' . e($it['variant_label']) . '</span>' : '' ?></td><td><?= e($it['sku'] ?? '') ?></td><td class="right"><?= (int) $it['quantity'] - (int) $it['refunded_qty'] ?></td><?php if ($invoice): ?><td class="right"><?= e(money((int) $it['unit_price_cents'])) ?></td><td class="right"><?= e(money((int) $it['subtotal_cents'])) ?></td><?php endif ?></tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <?php if ($invoice): ?>
    <dl class="kv slip__totals">
        <dt>Subtotal</dt><dd><?= e(money((int) $vo['subtotal_cents'])) ?></dd>
        <?php if ((int) $vo['discount_cents']): ?><dt>Discount</dt><dd>−<?= e(money((int) $vo['discount_cents'])) ?></dd><?php endif ?>
        <dt>Shipping</dt><dd><?= e(money((int) $vo['shipping_cents'])) ?></dd>
        <dt>Tax</dt><dd><?= e(money((int) $vo['tax_cents'])) ?></dd>
        <dt class="total">Total</dt><dd class="total"><?= e(money((int) $vo['total_cents'])) ?></dd>
    </dl>
    <?php endif ?>
    <?php if ($order['customer_note']): ?><p><strong>Note:</strong> <?= e($order['customer_note']) ?></p><?php endif ?>
    <p class="muted small slip__thanks">Thank you for shopping with <?= e($vendor['store_name']) ?> on <?= e(config('app.name')) ?>.</p>
</div>
<script src="<?= e(asset('assets/js/panel.js')) ?>" defer></script>
</body>
</html>
