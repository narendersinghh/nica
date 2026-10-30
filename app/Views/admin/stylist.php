<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\StylistsController;
$this->layout('layouts/panel');
$base = '/admin/stylists/' . $s['id'] . '/';
$rate = static fn ($r) => rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . '%';
$types = ['commission' => 'Commission', 'refund' => 'Refund', 'payout' => 'Payout', 'adjustment' => 'Adjustment'];
$canFinance = App\Http\Middleware\AdminAccess::can('finance');
?>
<p><span class="pill pill--<?= e($s['status']) ?>"><?= e(StylistsController::STATUSES[$s['status']]) ?></span> <span class="pill pill--pending"><?= e($tier['name']) ?> · <?= e($rate($tier['rate'])) ?></span> <code><?= e($s['code']) ?></code></p>
<div class="stats">
    <div class="stat"><div class="stat__label">Visits</div><div class="stat__value"><?= (int) $stats['clicks'] ?></div><div class="stat__meta"><?= (int) $stats['clicks_30'] ?> in 30 days · <?= e((string) $stats['conversion']) ?>% convert</div></div>
    <div class="stat"><div class="stat__label">Referred sales</div><div class="stat__value"><?= e(money($stats['sales'])) ?></div><div class="stat__meta"><?= (int) $stats['orders'] ?> orders</div></div>
    <div class="stat"><div class="stat__label">Commission earned</div><div class="stat__value"><?= e(money($stats['commission'])) ?></div><div class="stat__meta">Paid by the marketplace</div></div>
    <div class="stat stat--accent"><div class="stat__label">Available</div><div class="stat__value"><?= e(money($balance['withdrawable'])) ?></div><div class="stat__meta"><?= e(money($balance['pending'])) ?> pending</div></div>
</div>
<div class="grid grid--main">
    <div>
        <section class="card card--flush">
            <div class="card__head"><h2>Referred orders</h2></div>
            <?php if (!$referrals): ?><div class="empty"><?= picon('receipt') ?>No referred orders yet.</div><?php else: ?>
            <div class="table-wrap"><table class="table table--stack"><thead><tr><th>Order</th><th>Date</th><th class="right">Sale</th><th class="right">Commission</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($referrals as $r): ?><tr><td class="prod-col"><a href="<?= e(link_to('/admin/orders/' . $r['order_id'] . '/')) ?>">#<?= e($r['number']) ?></a> <span class="muted small"><?= e($r['email']) ?></span></td><td data-label="Date"><?= e(gmdate('M j, Y', strtotime($r['created_at']))) ?></td><td data-label="Sale" class="right num"><?= e(money((int) $r['base_cents'])) ?></td><td data-label="Commission" class="right num"><?= e(money((int) $r['commission_cents'])) ?> <span class="muted small"><?= e($rate($r['rate'])) ?></span></td><td data-label="Status"><span class="pill pill--<?= $r['status'] === 'earned' ? 'completed' : ($r['status'] === 'void' ? 'refunded' : 'pending') ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr><?php endforeach ?>
            </tbody></table></div>
            <?php endif ?>
        </section>
        <section class="card card--flush">
            <div class="card__head"><h2>Ledger</h2></div>
            <?php if (!$ledger['rows']): ?><div class="empty"><?= picon('wallet') ?>No entries yet.</div><?php else: ?>
            <div class="table-wrap"><table class="table table--stack"><thead><tr><th>Date</th><th>Description</th><th>Type</th><th class="right">Amount</th></tr></thead><tbody>
            <?php foreach ($ledger['rows'] as $l): ?><tr><td data-label="Date" class="nowrap"><?= e(gmdate('M j, Y', strtotime($l['created_at']))) ?></td><td data-label="Description"><?= e($l['description']) ?><?= $l['available_at'] === null ? ' <span class="muted small">(pending)</span>' : '' ?></td><td data-label="Type"><span class="pill pill--<?= e($l['type'] === 'commission' ? 'earning' : $l['type']) ?>"><?= e($types[$l['type']] ?? $l['type']) ?></span></td><td data-label="Amount" class="right num<?= (int) $l['amount_cents'] < 0 ? ' trend--down' : '' ?>"><?= e(money((int) $l['amount_cents'])) ?></td></tr><?php endforeach ?>
            </tbody></table></div>
            <?= $this->insert('vendor/_pager', ['page' => $ledger['page'], 'pages' => $ledger['pages'], 'base' => $base]) ?>
            <?php endif ?>
        </section>
    </div>
    <div>
        <section class="card">
            <h2>Status</h2>
            <?php if ($s['status'] === 'pending'): ?>
                <form method="post" action="<?= e(link_to($base . 'approve')) ?>"><?= csrf_field() ?><p><button class="btn btn--block" type="submit"><?= picon('check') ?>Approve stylist</button></p></form>
                <form method="post" action="<?= e(link_to($base . 'reject')) ?>" data-confirm="Reject this application?"><?= csrf_field() ?><div class="fld"><label for="f-rr">Reason (emailed)</label><textarea id="f-rr" name="reason" rows="2" maxlength="500"></textarea></div><button class="btn btn--danger btn--block" type="submit">Reject</button></form>
            <?php elseif ($s['status'] === 'approved'): ?>
                <form method="post" action="<?= e(link_to($base . 'suspend')) ?>" data-confirm="Suspend this stylist? Their link stops earning at once."><?= csrf_field() ?><div class="fld"><label for="f-sr">Reason (emailed)</label><textarea id="f-sr" name="reason" rows="2" maxlength="500"></textarea></div><button class="btn btn--danger btn--block" type="submit">Suspend</button></form>
            <?php else: ?>
                <form method="post" action="<?= e(link_to($base . 'reactivate')) ?>"><?= csrf_field() ?><button class="btn btn--block" type="submit">Reactivate</button></form>
            <?php endif ?>
        </section>
        <section class="card">
            <h2>Profile</h2>
            <dl class="kv kv--left small">
                <dt>Email</dt><dd><a href="<?= e(link_to('/admin/customers/' . $s['user_id'] . '/')) ?>"><?= e($s['email']) ?></a></dd>
                <dt>Salon</dt><dd><?= e((string) $s['salon_name']) ?: '—' ?></dd>
                <dt>Location</dt><dd><?= e($s['city'] . ', ' . $s['state']) ?></dd>
                <dt>Instagram</dt><dd><?= $s['instagram'] ? '<a href="https://www.instagram.com/' . e(rawurlencode($s['instagram'])) . '/" target="_blank" rel="noopener noreferrer">@' . e($s['instagram']) . '</a>' : '—' ?></dd>
                <dt>Payouts</dt><dd><?= e($payoutMethod) ?></dd>
                <dt>Applied</dt><dd><?= e(gmdate('M j, Y', strtotime($s['created_at']))) ?></dd>
            </dl>
            <?php if ($s['about']): ?><p class="small"><?= nl2br(e($s['about'])) ?></p><?php endif ?>
            <?php if ($sources): ?><h3>Where visits come from</h3><ul class="list-rows small"><?php foreach ($sources as $src): ?><li><span><?= e($src['host']) ?></span><span class="num"><?= (int) $src['n'] ?></span></li><?php endforeach ?></ul><?php endif ?>
        </section>
        <section class="card">
            <h2>Terms</h2>
            <form method="post" action="<?= e(link_to($base)) ?>">
                <?= csrf_field() ?>
                <div class="fld"><label for="f-tier">Tier</label><select id="f-tier" name="tier_override"><option value="">Automatic (from sales: <?= e($tier['overridden'] ? 'override active' : $tier['name']) ?>)</option><?php foreach ($tiers as $t): ?><option value="<?= e($t['key']) ?>"<?= $s['tier_override'] === $t['key'] ? ' selected' : '' ?>><?= e($t['name'] . ' · ' . $rate($t['rate'])) ?></option><?php endforeach ?></select></div>
                <div class="fld"><label for="f-th">Minimum payout ($)</label><input type="number" step="0.01" min="0" id="f-th" name="payout_threshold" value="<?= $s['payout_threshold_cents'] !== null ? e(number_format($s['payout_threshold_cents'] / 100, 2, '.', '')) : '' ?>" placeholder="Default <?= e(money($defaultThreshold)) ?>"></div>
                <div class="fld"><label for="f-note">Internal note</label><textarea id="f-note" name="admin_note" rows="2" maxlength="500"><?= e((string) $s['admin_note']) ?></textarea></div>
                <button class="btn btn--ghost btn--sm" type="submit">Save</button>
            </form>
        </section>
        <?php if ($canFinance): ?>
        <section class="card">
            <h2>Manual adjustment</h2>
            <form method="post" action="<?= e(link_to($base . 'adjust')) ?>" data-confirm="Add this adjustment?">
                <?= csrf_field() ?>
                <div class="fld"><label for="f-aa">Amount ($)</label><input type="text" id="f-aa" name="amount" inputmode="decimal" placeholder="-10.00 or 15.00" required></div>
                <div class="fld"><label for="f-ad">Description (the stylist sees this)</label><input type="text" id="f-ad" name="description" maxlength="255" required></div>
                <button class="btn btn--ghost btn--sm" type="submit">Add adjustment</button>
            </form>
        </section>
        <?php endif ?>
    </div>
</div>
