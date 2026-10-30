<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\PayoutsController;
use App\Services\Vendor\Earnings;
$this->layout('layouts/panel');
?>
<?php $this->start('actions') ?>
<a class="btn btn--ghost" href="<?= e(link_to('/admin/payouts/export')) ?>"><?= picon('download') ?>Ledger CSV</a>
<?php $this->stop() ?>
<nav class="chips" aria-label="Filter requests">
    <?php foreach (PayoutsController::STATUSES as $k => $l): ?><a href="<?= e(link_to('/admin/payouts/') . '?status=' . $k) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach ?>
</nav>
<div class="grid grid--main">
    <section class="card card--flush">
        <div class="card__head"><h2>Payout requests</h2></div>
        <?php if (!$rows): ?><div class="empty"><?= picon('wallet') ?><?= $status === 'open' ? 'Nothing to pay right now.' : 'No requests.' ?></div><?php else: ?>
        <div class="table-wrap"><table class="table table--stack">
            <thead><tr><th>Requested</th><th>Payee</th><th>Method</th><th class="right">Amount</th><th class="right">Balance</th><th><span class="sr-only">Open</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Requested" class="nowrap"><?= e(gmdate('M j, Y', strtotime($r['created_at']))) ?><?php if (in_array($r['status'], ['pending', 'approved'], true) && strtotime($r['created_at']) < time() - 3 * 86400): ?> <span class="pill pill--pending">&gt; 3 days</span><?php endif ?></td>
                    <td data-label="Payee"><a href="<?= e(link_to(($r['owner'] === 'stylist' ? '/admin/stylists/' : '/admin/vendors/') . $r['owner_id'] . '/')) ?>"><?= e($r['owner_name']) ?></a> <span class="pill<?= $r['owner'] === 'stylist' ? ' pill--shipped' : '' ?>"><?= $r['owner'] === 'stylist' ? 'Stylist' : 'Vendor' ?></span></td>
                    <td data-label="Method"><?= e(Earnings::METHODS[$r['method']] ?? $r['method']) ?></td>
                    <td data-label="Amount" class="right num"><strong><?= e(money((int) $r['amount_cents'])) ?></strong></td>
                    <td data-label="Balance" class="right num<?= (int) $r['balance'] < (int) $r['amount_cents'] && in_array($r['status'], ['pending', 'approved'], true) ? ' trend--down' : '' ?>"><?= e(money((int) $r['balance'])) ?></td>
                    <td class="right"><a class="btn btn--sm<?= in_array($r['status'], ['pending', 'approved'], true) ? '' : ' btn--ghost' ?>" href="<?= e(link_to('/admin/payouts/' . ($r['owner'] === 'stylist' ? 'stylist/' : '') . $r['id'] . '/')) ?>"><?= in_array($r['status'], ['pending', 'approved'], true) ? 'Review' : 'View' ?></a></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table></div>
        <?php endif ?>
    </section>
    <section class="card">
        <h2>Vendor balances</h2>
        <p class="small muted">Money vendors can withdraw now.</p>
        <ul class="list-rows">
            <?php foreach ($balances as $b): ?><li><a href="<?= e(link_to('/admin/payouts/ledger/' . $b['id'] . '/')) ?>"><?= e($b['store_name']) ?></a><span class="num"><?= e(money((int) $b['available'])) ?></span></li><?php endforeach ?>
            <?php if (!$balances): ?><li class="muted small">No balances.</li><?php endif ?>
        </ul>
    </section>
</div>
