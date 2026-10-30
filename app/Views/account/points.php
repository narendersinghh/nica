<?php $this->layout('account/layout');
$reasons = ['signup' => 'Signup bonus', 'order' => 'Purchase', 'redeem' => 'Redeemed at checkout', 'restore' => 'Returned (order not completed)', 'admin' => 'Adjustment', 'import' => 'Balance from previous site', 'referral' => 'Referral', 'review' => 'Review']; ?>
<div class="points-hero">
    <p class="points-hero__label">Your points</p>
    <p class="points-hero__value"><?= (int) $balance ?></p>
    <p>Worth <strong><?= e(money(App\Services\Points::value((int) $balance))) ?></strong> off your next order (<?= (int) $rate ?> points = $1). Use them at checkout.</p>
</div>
<h2 class="account-subtitle">Points history</h2>
<?php if (!$history): ?><p>No points activity yet.</p><?php else: ?>
<table class="account-table">
    <thead><tr><th scope="col">Date</th><th scope="col">Activity</th><th scope="col">Points</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr><td data-label="Date"><?= e(date('M j, Y', strtotime($h['created_at']))) ?></td>
            <td data-label="Activity"><?= e($reasons[$h['reason']] ?? $h['reason']) ?><?= $h['order_number'] ? ' · <a href="' . e(link_to('/my-account/view-order/' . $h['order_number'] . '/')) . '">#' . e($h['order_number']) . '</a>' : '' ?></td>
            <td data-label="Points" class="<?= (int) $h['points'] < 0 ? 'neg' : 'pos' ?>"><?= (int) $h['points'] > 0 ? '+' : '' ?><?= (int) $h['points'] ?></td></tr>
    <?php endforeach ?>
    </tbody>
</table>
<?php endif ?>
