<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$ship = json_decode($order['shipping_address'], true) ?: [];
$bill = json_decode($order['billing_address'], true) ?: [];
$base = '/admin/orders/' . $order['id'] . '/';
$paid = (bool) $order['paid_at'] || in_array($order['status'], ['processing', 'on-hold', 'completed'], true);
$addr = static fn (array $a) => e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))) . '<br>' . (!empty($a['company']) ? e($a['company']) . '<br>' : '') . e($a['address_1'] ?? '') . (!empty($a['address_2']) ? ', ' . e($a['address_2']) : '') . '<br>' . e(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postcode'] ?? ''));
?>
<?php $this->start('actions') ?>
<form method="post" action="<?= e(link_to($base . 'resend')) ?>"><?= csrf_field() ?><button class="btn btn--ghost" type="submit"><?= picon('receipt') ?>Re-send confirmation</button></form>
<?php $this->stop() ?>

<p><?= $this->insert('admin/_order-status', ['status' => $order['status']]) ?> <span class="muted small">Placed <?= e(gmdate('M j, Y g:ia', strtotime($order['created_at']))) ?> UTC<?= $order['legacy_wp_id'] ? ' · imported from WordPress' : '' ?></span></p>

<?php if ($order['status'] === 'on-hold'): ?>
<div class="alert alert--warn"><?= picon('alert') ?><div><strong>This order is on hold.</strong> Check the notes below (stock or payment amount), then release it to the vendors<?= $order['paid_at'] ? '' : ' or cancel it' ?>.
    <div class="toolbar">
        <?php if ($order['paid_at']): ?><form method="post" action="<?= e(link_to($base . 'release')) ?>"><?= csrf_field() ?><button class="btn btn--sm" type="submit">Release to vendors</button></form><?php endif ?>
    </div></div></div>
<?php endif ?>

<div class="grid grid--main">
    <div>
        <?php foreach ($vendorOrders as $vo): $left = min((int) $vo['total_cents'] - (int) $vo['refunded_cents'], (int) $order['total_cents'] - (int) $order['refunded_cents']); ?>
        <section class="card card--flush">
            <div class="card__head"><h2><?= e($vo['store_name']) ?></h2><span><?= $this->insert('admin/_order-status', ['status' => $vo['status']]) ?><?= $vo['payout_mode'] === 'stripe_connect' ? ' <span class="pill">' . picon('bolt') . 'Stripe split</span>' : '' ?></span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Total</th></tr></thead>
                <tbody>
                <?php foreach ($vo['items'] as $it): ?>
                    <tr><td><strong><?= e($it['name']) ?></strong><?= $it['variant_label'] ? ' <span class="muted small">' . e($it['variant_label']) . '</span>' : '' ?><?= $it['sku'] ? '<div class="muted small">SKU ' . e($it['sku']) . '</div>' : '' ?></td><td class="right num"><?= (int) $it['quantity'] ?><?= (int) $it['refunded_qty'] ? ' <span class="pill pill--refunded">−' . (int) $it['refunded_qty'] . '</span>' : '' ?></td><td class="right num"><?= e(money((int) $it['unit_price_cents'])) ?></td><td class="right num"><?= e(money((int) $it['subtotal_cents'])) ?></td></tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <div class="card__foot grid grid--2">
                <dl class="kv">
                    <dt>Subtotal</dt><dd class="num"><?= e(money((int) $vo['subtotal_cents'])) ?></dd>
                    <?php if ((int) $vo['discount_cents']): ?><dt>Discount</dt><dd class="num">−<?= e(money((int) $vo['discount_cents'])) ?></dd><?php endif ?>
                    <dt>Shipping <span class="muted small"><?= e((string) $vo['shipping_method']) ?></span></dt><dd class="num"><?= e(money((int) $vo['shipping_cents'])) ?></dd>
                    <dt>Tax</dt><dd class="num"><?= e(money((int) $vo['tax_cents'])) ?></dd>
                    <dt class="total">Total</dt><dd class="total num"><?= e(money((int) $vo['total_cents'])) ?></dd>
                </dl>
                <dl class="kv">
                    <dt>Commission (<?= e(rtrim(rtrim((string) $vo['commission_rate'], '0'), '.')) ?>%)</dt><dd class="num"><?= e(money((int) $vo['commission_cents'])) ?></dd>
                    <dt>Vendor earning</dt><dd class="num"><?= e(money((int) $vo['vendor_earning_cents'])) ?></dd>
                    <?php if ((int) $vo['refunded_cents']): ?><dt>Refunded</dt><dd class="num trend--down">−<?= e(money((int) $vo['refunded_cents'])) ?></dd><?php endif ?>
                    <?php if ($vo['tracking_number']): ?><dt>Tracking</dt><dd><?= $vo['tracking_url'] ? '<a href="' . e($vo['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . e($vo['tracking_carrier'] . ' ' . $vo['tracking_number']) . '</a>' : e($vo['tracking_carrier'] . ' ' . $vo['tracking_number']) ?></dd><?php endif ?>
                </dl>
            </div>
            <?php if ($paid && $left > 0): ?>
            <details class="card__foot refund-box">
                <summary><?= picon('refund') ?> Refund from <?= e($vo['store_name']) ?></summary>
                <form method="post" action="<?= e(link_to($base . 'refund')) ?>" data-confirm="Refund this amount to the customer?">
                    <?= csrf_field() ?><input type="hidden" name="vendor_order_id" value="<?= (int) $vo['id'] ?>">
                    <div class="form-grid">
                        <div class="fld"><label for="ra-<?= (int) $vo['id'] ?>">Amount (up to <?= e(money($left)) ?>)</label><div class="prefix"><span>$</span><input type="text" inputmode="decimal" id="ra-<?= (int) $vo['id'] ?>" name="amount" value="<?= e(number_format($left / 100, 2, '.', '')) ?>"></div></div>
                        <div class="fld"><label for="rr-<?= (int) $vo['id'] ?>">Reason</label><input type="text" id="rr-<?= (int) $vo['id'] ?>" name="reason" maxlength="500" placeholder="Shown to the customer"></div>
                        <div class="fld full"><label class="check"><input type="checkbox" name="restock" value="1" checked>Put items back in stock (full refunds only)</label></div>
                    </div>
                    <button class="btn btn--danger btn--sm" type="submit">Refund</button>
                    <p class="hint muted small"><?= $order['legacy_wp_id'] ? 'This order was paid on the old site: the refund is recorded here and must also be made in the Stripe dashboard.' : 'The money goes back to the customer\'s card through Stripe.' ?> The vendor's share is taken from their balance.</p>
                </form>
            </details>
            <?php endif ?>
        </section>
        <?php endforeach ?>

        <?php if ($refunds): ?>
        <section class="card">
            <h2>Refunds</h2>
            <ul class="list-rows">
                <?php foreach ($refunds as $r): ?><li><span><?= e(gmdate('M j, Y', strtotime($r['created_at']))) ?> · <?= e((string) $r['reason']) ?: '<span class="muted">No reason</span>' ?><span class="muted small"><?= $r['author'] ? ' · ' . e($r['author']) : '' ?><?= $r['stripe_refund_id'] ? ' · ' . e($r['stripe_refund_id']) : '' ?></span></span><strong class="num trend--down">−<?= e(money((int) $r['amount_cents'])) ?></strong></li><?php endforeach ?>
            </ul>
        </section>
        <?php endif ?>

        <section class="card" id="notes">
            <h2>Notes</h2>
            <form method="post" action="<?= e(link_to($base . 'note')) ?>" class="fld">
                <?= csrf_field() ?>
                <label class="sr-only" for="f-note">Add a note</label>
                <textarea id="f-note" name="note" rows="2" maxlength="2000" required placeholder="Add a note…"></textarea>
                <div class="toolbar"><label class="check"><input type="checkbox" name="to_customer" value="1">Visible to the customer</label><button class="btn btn--ghost btn--sm" type="submit">Add note</button></div>
            </form>
            <ul class="timeline">
                <?php foreach ($notes as $n): ?><li class="<?= (int) $n['is_customer_visible'] ? 'is-public' : '' ?>"><?= nl2br(e($n['note'])) ?><time><?= e(gmdate('M j, Y g:ia', strtotime($n['created_at']))) ?><?= $n['author'] ? ' · ' . e($n['author']) : '' ?><?= (int) $n['is_customer_visible'] ? ' · visible to customer' : '' ?></time></li><?php endforeach ?>
            </ul>
        </section>
    </div>
    <div>
        <section class="card">
            <h2>Totals</h2>
            <dl class="kv">
                <dt>Items</dt><dd class="num"><?= e(money((int) $order['subtotal_cents'])) ?></dd>
                <?php if ((int) $order['discount_cents']): ?><dt>Discount <span class="muted small"><?= e(implode(', ', json_decode((string) $order['coupon_codes'], true) ?: [])) ?></span></dt><dd class="num">−<?= e(money((int) $order['discount_cents'])) ?></dd><?php endif ?>
                <?php if ((int) $order['points_discount_cents']): ?><dt>Points</dt><dd class="num">−<?= e(money((int) $order['points_discount_cents'])) ?></dd><?php endif ?>
                <dt>Shipping</dt><dd class="num"><?= e(money((int) $order['shipping_cents'])) ?></dd>
                <dt>Tax</dt><dd class="num"><?= e(money((int) $order['tax_cents'])) ?></dd>
                <dt class="total">Total</dt><dd class="total num"><?= e(money((int) $order['total_cents'])) ?></dd>
                <?php if ((int) $order['refunded_cents']): ?><dt>Refunded</dt><dd class="num trend--down">−<?= e(money((int) $order['refunded_cents'])) ?></dd><?php endif ?>
            </dl>
            <p class="muted small"><?= e((string) $order['payment_method_title']) ?: 'No payment yet' ?><?= $order['paid_at'] ? ' · paid ' . e(gmdate('M j, Y', strtotime($order['paid_at']))) : '' ?><?php if ($order['stripe_payment_intent_id']): ?><br><?= e($order['stripe_payment_intent_id']) ?><?php endif ?></p>
            <?php if (in_array($order['status'], ['processing'], true)): ?>
                <form method="post" action="<?= e(link_to($base . 'complete')) ?>" data-confirm="Mark every vendor's part as completed? Vendor earnings become available for payout."><?= csrf_field() ?><button class="btn btn--ghost btn--block" type="submit">Mark order completed</button></form>
            <?php endif ?>
            <?php if (!$order['paid_at'] && in_array($order['status'], ['pending', 'on-hold', 'failed'], true)): ?>
                <form method="post" action="<?= e(link_to($base . 'cancel')) ?>" data-confirm="Cancel this unpaid order? Stock and points go back."><?= csrf_field() ?><p><button class="btn btn--danger btn--block" type="submit">Cancel order</button></p></form>
            <?php endif ?>
        </section>
        <section class="card">
            <h2>Customer</h2>
            <p><?php if ($customer): ?><a href="<?= e(link_to('/admin/customers/' . $customer['id'] . '/')) ?>"><strong><?= e(trim($customer['first_name'] . ' ' . $customer['last_name']) ?: $customer['email']) ?></strong></a><?php else: ?><strong>Guest</strong><?php endif ?><br><a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a><?= !empty($bill['phone']) ? '<br>' . e($bill['phone']) : '' ?></p>
            <h3>Shipping address</h3><address class="address"><?= $addr($ship) ?></address>
            <h3>Billing address</h3><address class="address"><?= $addr($bill) ?></address>
            <?php if ($order['customer_note']): ?><div class="alert alert--info small"><div><strong>Customer note:</strong> <?= e($order['customer_note']) ?></div></div><?php endif ?>
            <?php if ($order['referral_code']): ?><p class="small muted">Referral: <?= e($order['referral_code']) ?></p><?php endif ?>
        </section>
    </div>
</div>
