<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$types = ['earning' => 'Sale', 'refund' => 'Refund', 'payout' => 'Payout', 'transfer' => 'Stripe transfer', 'adjustment' => 'Adjustment'];
?>
<div class="stats">
    <div class="stat stat--accent"><div class="stat__label">Available</div><div class="stat__value"><?= e(money($balance['available'])) ?></div><div class="stat__meta"><?= e(money($balance['reserved'])) ?> requested</div></div>
    <div class="stat"><div class="stat__label">Pending</div><div class="stat__value"><?= e(money($balance['pending'])) ?></div></div>
    <div class="stat"><div class="stat__label">Paid out</div><div class="stat__value"><?= e(money($balance['paid_out'])) ?></div></div>
    <div class="stat"><div class="stat__label">Lifetime earnings</div><div class="stat__value"><?= e(money($balance['lifetime'])) ?></div></div>
</div>
<div class="grid grid--main">
    <section class="card card--flush">
        <?php if (!$ledger['rows']): ?><div class="empty"><?= picon('wallet') ?>No entries yet.</div><?php else: ?>
        <div class="table-wrap"><table class="table table--stack">
            <thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Available</th><th class="right">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($ledger['rows'] as $row): ?>
                <tr>
                    <td data-label="Date" class="nowrap"><?= e(gmdate('M j, Y', strtotime($row['created_at']))) ?></td>
                    <td data-label="Description"><?= e($row['description']) ?><?php if ($row['reference']): ?><div class="muted small"><?= e($row['reference']) ?></div><?php endif ?></td>
                    <td data-label="Type"><span class="pill pill--<?= e($row['type']) ?>"><?= e($types[$row['type']] ?? $row['type']) ?></span></td>
                    <td data-label="Available" class="small"><?= $row['available_at'] ? e(gmdate('M j, Y', strtotime($row['available_at']))) : '<span class="muted">When completed</span>' ?></td>
                    <td data-label="Amount" class="right num<?= (int) $row['amount_cents'] < 0 ? ' trend--down' : '' ?>"><?= e(money((int) $row['amount_cents'])) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?= $this->insert('vendor/_pager', ['page' => $ledger['page'], 'pages' => $ledger['pages'], 'base' => '/admin/payouts/ledger/' . $v['id'] . '/']) ?>
        <?php endif ?>
    </section>
    <section class="card">
        <h2>Manual adjustment</h2>
        <p class="small muted">For corrections such as a chargeback, a fee or a goodwill credit. Use a minus sign to deduct.</p>
        <form method="post" action="<?= e(link_to('/admin/payouts/ledger/' . $v['id'] . '/adjust')) ?>" data-confirm="Add this adjustment to the vendor's balance?">
            <?= csrf_field() ?>
            <div class="fld<?= isset($errors['amount']) ? ' fld--error' : '' ?>"><label for="f-aa">Amount ($)</label><input type="text" id="f-aa" name="amount" inputmode="decimal" placeholder="-25.00 or 10.00" required value="<?= e(old('amount')) ?>"></div>
            <div class="fld<?= isset($errors['description']) ? ' fld--error' : '' ?>"><label for="f-ad">Description (the vendor sees this)</label><input type="text" id="f-ad" name="description" maxlength="255" required value="<?= e(old('description')) ?>"></div>
            <button class="btn btn--ghost" type="submit">Add adjustment</button>
        </form>
    </section>
</div>
