<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\Earnings;
$this->layout('layouts/panel');
$open = array_filter($requests, static fn ($r) => $r['status'] === 'pending');
$canRequest = $hasMethod && $balance['withdrawable'] >= $threshold && !$open && $vendor['status'] === 'approved';
$typeLabels = ['earning' => 'Sale', 'refund' => 'Refund', 'payout' => 'Payout', 'transfer' => 'Stripe transfer', 'adjustment' => 'Adjustment'];
?>
<div class="stats">
    <div class="stat stat--accent"><div class="stat__label">Available to withdraw</div><div class="stat__value"><?= e(money($balance['withdrawable'])) ?></div><div class="stat__meta"><?= $balance['reserved'] ? e(money($balance['reserved'])) . ' requested' : 'Minimum payout ' . e(money($threshold)) ?></div></div>
    <div class="stat"><div class="stat__label">Pending</div><div class="stat__value"><?= e(money($balance['pending'])) ?></div><div class="stat__meta">From orders not completed yet</div></div>
    <div class="stat"><div class="stat__label">Paid out</div><div class="stat__value"><?= e(money($balance['paid_out'])) ?></div><div class="stat__meta">PayPal, bank and Stripe</div></div>
    <div class="stat"><div class="stat__label">Lifetime earnings</div><div class="stat__value"><?= e(money($balance['lifetime'])) ?></div><div class="stat__meta">After commission and refunds</div></div>
</div>

<div class="grid grid--main">
    <div>
        <section class="card card--flush">
            <div class="card__head"><h2>Activity</h2></div>
            <?php if (!$ledger['rows']): ?>
                <div class="empty"><?= picon('wallet') ?>Your earnings will show here after your first sale.</div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--stack">
                <thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Status</th><th class="right">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($ledger['rows'] as $row): $avail = $row['available_at'] !== null && $row['available_at'] <= gmdate('Y-m-d H:i:s'); ?>
                    <tr>
                        <td data-label="Date" class="nowrap"><?= e(gmdate('M j, Y', strtotime($row['created_at']))) ?></td>
                        <td data-label="Description"><?php if ($row['vendor_order_id']): ?><a href="<?= e(link_to('/dashboard/orders/' . $row['vendor_order_id'] . '/')) ?>"><?= e($row['description']) ?></a><?php else: ?><?= e($row['description']) ?><?php endif ?></td>
                        <td data-label="Type"><?= $this->insert('vendor/_status', ['status' => $row['type'], 'label' => $typeLabels[$row['type']] ?? $row['type']]) ?></td>
                        <td data-label="Status" class="small"><?= $avail ? 'Available' : '<span class="muted">Pending</span>' ?></td>
                        <td data-label="Amount" class="right num<?= (int) $row['amount_cents'] < 0 ? ' trend--down' : '' ?>"><?= e(money((int) $row['amount_cents'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?= $this->insert('vendor/_pager', ['page' => $ledger['page'], 'pages' => $ledger['pages'], 'base' => '/dashboard/withdraw/']) ?>
            <?php endif ?>
        </section>
    </div>
    <div>
        <section class="card">
            <h2>Request a payout</h2>
            <?php if ($connected): ?>
                <p class="small"><?= picon('bolt') ?> Stripe is connected, so new orders are paid to your Stripe account automatically. Use this form for any balance from earlier orders.</p>
            <?php endif ?>
            <p class="small muted">Paid to: <strong><?= e($method) ?></strong> · <a href="<?= e(link_to('/dashboard/settings/payment/')) ?>">Change</a></p>
            <form method="post" action="<?= e(link_to('/dashboard/withdraw/request')) ?>">
                <?= csrf_field() ?>
                <div class="fld"><label for="f-amount">Amount</label><div class="prefix"><span>$</span><input type="text" inputmode="decimal" id="f-amount" name="amount" value="<?= e(number_format($balance['withdrawable'] / 100, 2, '.', '')) ?>"<?= $canRequest ? '' : ' disabled' ?>></div>
                    <p class="hint">Minimum <?= e(money($threshold)) ?>. Up to <?= e(money($balance['withdrawable'])) ?>.</p></div>
                <div class="fld"><label for="f-wnote">Note <span class="muted">(optional)</span></label><input type="text" id="f-wnote" name="note" maxlength="500"<?= $canRequest ? '' : ' disabled' ?>></div>
                <button class="btn btn--block" type="submit"<?= $canRequest ? '' : ' disabled' ?>>Request payout</button>
                <?php if (!$canRequest): ?><p class="hint muted small">
                    <?php if ($vendor['status'] !== 'approved'): ?>Available once your store is approved.
                    <?php elseif (!$hasMethod): ?>Add your PayPal or bank details first.
                    <?php elseif ($open): ?>You have a request in progress.
                    <?php else: ?>You can request a payout when your balance reaches <?= e(money($threshold)) ?>.<?php endif ?>
                </p><?php endif ?>
            </form>
        </section>

        <section class="card">
            <h2>Payout requests</h2>
            <?php if (!$requests): ?>
                <p class="small muted">No requests yet.</p>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($requests as $r): ?>
                    <li>
                        <span><strong class="num"><?= e(money((int) $r['amount_cents'])) ?></strong><br><span class="muted small"><?= e(gmdate('M j, Y', strtotime($r['created_at']))) ?> · <?= e(Earnings::METHODS[$r['method']] ?? $r['method']) ?><?= $r['reference'] ? ' · ' . e($r['reference']) : '' ?></span><?php if ($r['admin_note']): ?><br><span class="small"><?= e($r['admin_note']) ?></span><?php endif ?></span>
                        <span class="right"><?= $this->insert('vendor/_status', ['status' => $r['status'] === 'approved' ? 'approved-req' : $r['status'], 'label' => ucfirst($r['status'])]) ?>
                        <?php if ($r['status'] === 'pending'): ?><form class="inline-form" method="post" action="<?= e(link_to('/dashboard/withdraw/' . $r['id'] . '/cancel')) ?>" data-confirm="Cancel this payout request?"><?= csrf_field() ?><br><button class="link-btn link-btn--danger small" type="submit">Cancel</button></form><?php endif ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>
    </div>
</div>
