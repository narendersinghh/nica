<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\VendorOrders;
$this->layout('layouts/panel');
$order = $vo['order'];
$ship = json_decode($order['shipping_address'], true) ?: [];
$bill = json_decode($order['billing_address'], true) ?: [];
$flow = ['processing' => 'Paid', 'shipped' => 'Shipped', 'completed' => 'Completed'];
$reached = array_search($vo['status'], array_keys($flow), true);
$base = '/dashboard/orders/' . $vo['id'] . '/';
?>
<?php $this->start('actions') ?>
<a class="btn btn--ghost" href="<?= e(link_to($base . 'slip/')) ?>" target="_blank" rel="noopener"><?= picon('print') ?>Packing slip</a>
<a class="btn btn--ghost" href="<?= e(link_to($base . 'slip/?type=invoice')) ?>" target="_blank" rel="noopener">Invoice</a>
<?php $this->stop() ?>

<?php if ($reached !== false): ?>
<div class="steps" aria-label="Progress">
    <?php foreach (array_values($flow) as $i => $label): ?>
        <span class="<?= $i < $reached ? 'is-done' : ($i === $reached ? 'is-current' : '') ?>"><?= e($label) ?></span>
    <?php endforeach ?>
</div>
<?php else: ?>
<p><?= $this->insert('vendor/_status', ['status' => $vo['status']]) ?></p>
<?php endif ?>

<div class="grid grid--main">
    <div>
        <section class="card card--flush">
            <div class="card__head"><h2>Items</h2><span class="muted small">Placed <?= e(gmdate('M j, Y g:ia', strtotime($order['created_at']))) ?> UTC</span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Product</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Total</th></tr></thead>
                <tbody>
                <?php foreach ($vo['items'] as $it): ?>
                    <tr>
                        <td><div class="prod-cell"><span class="thumb"><?php if ($it['image_media_id']): ?><?= image((int) $it['image_media_id'], ['size' => 'thumbnail', 'width' => 44, 'height' => 44, 'alt' => '']) ?><?php endif ?></span><div><strong><?= e($it['name']) ?></strong><?php if ($it['variant_label']): ?><span class="muted small"><?= e($it['variant_label']) ?></span><?php endif ?><?php if ($it['sku']): ?><div class="muted small">SKU <?= e($it['sku']) ?></div><?php endif ?></div></div></td>
                        <td class="right num"><?= (int) $it['quantity'] ?><?= (int) $it['refunded_qty'] ? ' <span class="pill pill--refunded">−' . (int) $it['refunded_qty'] . '</span>' : '' ?></td>
                        <td class="right num"><?= e(money((int) $it['unit_price_cents'])) ?></td>
                        <td class="right num"><?= e(money((int) $it['subtotal_cents'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <div class="card__foot">
                <dl class="kv">
                    <dt>Subtotal</dt><dd class="num"><?= e(money((int) $vo['subtotal_cents'])) ?></dd>
                    <?php if ((int) $vo['discount_cents']): ?><dt>Discount<?= (int) $vo['vendor_discount_cents'] ? ' <span class="muted small">(' . e(money((int) $vo['vendor_discount_cents'])) . ' from your coupon)</span>' : '' ?></dt><dd class="num">−<?= e(money((int) $vo['discount_cents'])) ?></dd><?php endif ?>
                    <dt>Shipping <span class="muted small"><?= e($vo['shipping_method'] ?? '') ?></span></dt><dd class="num"><?= e(money((int) $vo['shipping_cents'])) ?></dd>
                    <dt>Tax</dt><dd class="num"><?= e(money((int) $vo['tax_cents'])) ?></dd>
                    <dt>Customer paid</dt><dd class="num"><?= e(money((int) $vo['total_cents'])) ?></dd>
                    <dt>Commission (<?= e(rtrim(rtrim((string) $vo['commission_rate'], '0'), '.')) ?>%)</dt><dd class="num">−<?= e(money((int) $vo['commission_cents'])) ?></dd>
                    <?php if ((int) $vo['refunded_cents']): ?><dt>Refunded</dt><dd class="num">−<?= e(money((int) $vo['refunded_cents'])) ?></dd><?php endif ?>
                    <dt class="total">Your earning</dt><dd class="total num"><?= e(money((int) $vo['vendor_earning_cents'])) ?></dd>
                </dl>
                <p class="muted small">
                    <?php if ($vo['payout_mode'] === 'stripe_connect'): ?><?= picon('bolt') ?> Paid to your Stripe account<?= $vo['stripe_transfer_id'] ? ' (' . e($vo['stripe_transfer_id']) . ')' : '' ?>.
                    <?php elseif ($vo['status'] === 'completed'): ?>Added to your available balance.
                    <?php elseif (in_array($vo['status'], ['refunded', 'cancelled', 'failed', 'pending'], true)): ?>No earning is paid for <?= e(strtolower(VendorOrders::STATUS_LABELS[$vo['status']])) ?> orders.
                    <?php else: ?>Becomes available for payout when you mark the order completed.<?php endif ?>
                </p>
            </div>
        </section>

        <section class="card" id="notes">
            <h2>Notes</h2>
            <form method="post" action="<?= e(link_to($base . 'note')) ?>" class="fld">
                <?= csrf_field() ?>
                <label class="sr-only" for="f-note">Add a note</label>
                <textarea id="f-note" name="note" rows="3" maxlength="2000" required placeholder="Add a note…"></textarea>
                <div class="toolbar">
                    <label class="check"><input type="checkbox" name="to_customer" value="1">Show this note to the customer</label>
                    <button class="btn btn--ghost btn--sm" type="submit">Add note</button>
                </div>
            </form>
            <?php if ($vo['notes']): ?>
            <ul class="timeline">
                <?php foreach ($vo['notes'] as $n): ?>
                    <li class="<?= (int) $n['is_customer_visible'] ? 'is-public' : '' ?>"><?= nl2br(e($n['note'])) ?><time><?= e(gmdate('M j, Y g:ia', strtotime($n['created_at']))) ?><?= (int) $n['is_customer_visible'] ? ' · visible to customer' : '' ?></time></li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>
    </div>

    <div>
        <?php if (in_array($vo['status'], ['processing', 'shipped'], true)): ?>
        <section class="card">
            <h2><?= $vo['status'] === 'processing' ? 'Ship this order' : 'Shipment' ?></h2>
            <?php if ($vo['status'] === 'shipped'): ?>
                <p class="small"><?= picon('truck') ?> <?= e($vo['tracking_carrier']) ?> · <?php if ($vo['tracking_url']): ?><a href="<?= e($vo['tracking_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($vo['tracking_number']) ?></a><?php else: ?><?= e($vo['tracking_number']) ?><?php endif ?><br><span class="muted">Shipped <?= e(gmdate('M j, Y', strtotime($vo['shipped_at']))) ?></span></p>
            <?php endif ?>
            <form method="post" action="<?= e(link_to($base . 'ship')) ?>"<?= $vo['status'] === 'shipped' ? ' hidden data-edit-tracking' : '' ?>>
                <?= csrf_field() ?>
                <div class="fld<?= isset($errors['carrier']) ? ' fld--error' : '' ?>"><label for="f-carrier">Carrier</label>
                    <select id="f-carrier" name="carrier"><?php foreach (VendorOrders::CARRIERS as $k => [$label]): ?><option value="<?= e($k) ?>"<?= old('carrier', 'usps') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
                <div class="fld<?= isset($errors['tracking_number']) ? ' fld--error' : '' ?>"><label for="f-track">Tracking number</label><input type="text" id="f-track" name="tracking_number" value="<?= e(old('tracking_number', (string) $vo['tracking_number'])) ?>" required maxlength="100" autocomplete="off"><?php if (isset($errors['tracking_number'])): ?><p class="err">Please enter a valid tracking number.</p><?php endif ?></div>
                <button class="btn btn--block" type="submit"><?= picon('truck') ?><?= $vo['status'] === 'shipped' ? 'Update tracking' : 'Mark as shipped' ?></button>
                <p class="hint muted small">The customer gets an email with the tracking link.</p>
            </form>
            <?php if ($vo['status'] === 'shipped'): ?><button type="button" class="link-btn small" data-show="[data-edit-tracking]">Edit tracking</button><?php endif ?>
            <hr class="sep">
            <form method="post" action="<?= e(link_to($base . 'complete')) ?>" data-confirm="Mark this order as completed? Do this once the customer has received it.">
                <?= csrf_field() ?>
                <button class="btn btn--ghost btn--block" type="submit"><?= picon('check') ?>Mark as completed</button>
            </form>
        </section>
        <?php endif ?>

        <section class="card">
            <h2>Ship to</h2>
            <address class="address">
                <strong><?= e(trim(($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? ''))) ?></strong><br>
                <?php if (!empty($ship['company'])): ?><?= e($ship['company']) ?><br><?php endif ?>
                <?= e($ship['address_1'] ?? '') ?><?= !empty($ship['address_2']) ? ', ' . e($ship['address_2']) : '' ?><br>
                <?= e(($ship['city'] ?? '') . ', ' . ($ship['state'] ?? '') . ' ' . ($ship['postcode'] ?? '')) ?><br>
                <?= e($ship['country'] ?? 'US') ?>
            </address>
            <?php if (!empty($bill['phone'])): ?><p class="small">Phone: <?= e($bill['phone']) ?></p><?php endif ?>
            <?php if ($order['customer_note']): ?><div class="alert alert--info small"><div><strong>Customer note:</strong> <?= e($order['customer_note']) ?></div></div><?php endif ?>
        </section>
    </div>
</div>
