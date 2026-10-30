<?php $this->layout('account/layout');
$rate = static fn ($r) => rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . '%';
$next = $tier['next'];
$progress = $next ? min(100, (int) round($tier['sales_cents'] / max(1, (int) $next['min_cents']) * 100)) : 100;
$open = array_filter($requests, static fn ($r) => $r['status'] === 'pending');
$canRequest = $details && $balance['withdrawable'] >= $threshold && !$open;
$fe = static fn (string $k) => $errors[$k] ?? null;
$statusLabels = ['pending' => 'Pending', 'earned' => 'Earned', 'void' => 'Refunded'];
?>
<div class="points-hero stylist-hero">
    <p class="points-hero__label"><?= e($tier['name']) ?> stylist · <?= e($rate($tier['rate'])) ?> commission</p>
    <p class="points-hero__value"><?= e(money($balance['withdrawable'])) ?></p>
    <p>available · <?= e(money($balance['pending'])) ?> pending until orders are delivered</p>
</div>

<h2 class="account-subtitle">Your link</h2>
<div class="ref-share">
    <label class="sr-only" for="ref-link">Your referral link</label>
    <input id="ref-link" type="text" value="<?= e($link) ?>" readonly data-select-all>
    <button class="btn btn--dark" type="button" data-copy="#ref-link">Copy link</button>
</div>
<p class="field__hint">Or give clients your code <strong><?= e($stylist['code']) ?></strong> to type at checkout. Add <code>?ref=<?= e($stylist['code']) ?></code> to any product link to share a specific item. Orders count for <?= (int) App\Services\Settings::get('referrals.cookie_days', 30) ?> days after a click.</p>

<div class="account-cards stylist-stats">
    <div class="account-card"><strong><?= (int) $stats['clicks_30'] ?></strong><span>Visits, last 30 days</span></div>
    <div class="account-card"><strong><?= (int) $stats['orders'] ?></strong><span>Orders referred</span></div>
    <div class="account-card"><strong><?= e(money($stats['sales'])) ?></strong><span>Referred sales</span></div>
    <div class="account-card"><strong><?= e(money($stats['commission'])) ?></strong><span>Commission earned</span></div>
</div>

<h2 class="account-subtitle">Your tier</h2>
<?php if ($next): ?>
    <p><?= e(money($tier['sales_cents'])) ?> referred in the last <?= (int) $windowDays ?> days. <?= e(money((int) $next['min_cents'] - $tier['sales_cents'])) ?> more to reach <strong><?= e($next['name']) ?></strong> (<?= e($rate($next['rate'])) ?>).</p>
    <div class="meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progress ?>"><span data-width="<?= $progress ?>"></span></div>
<?php else: ?>
    <p>You are at the top tier<?= $tier['overridden'] ? ' (set by our team)' : '' ?>. Thank you!</p>
<?php endif ?>

<h2 class="account-subtitle">Referred orders</h2>
<?php if (!$recent): ?><p>No referred orders yet. Share your link to get started.</p><?php else: ?>
<table class="account-table">
    <thead><tr><th scope="col">Date</th><th scope="col">Order</th><th scope="col">Sale</th><th scope="col">Commission</th><th scope="col">Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr><td data-label="Date"><?= e(date('M j, Y', strtotime($r['ordered_at']))) ?></td><td data-label="Order">#<?= e($r['number']) ?></td><td data-label="Sale"><?= e(money((int) $r['base_cents'])) ?></td><td data-label="Commission"><?= e(money((int) $r['commission_cents'])) ?> <small>(<?= e($rate($r['rate'])) ?>)</small></td><td data-label="Status"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
</table>
<p class="field__hint">Customers' names are kept private. Commission becomes available once the order is delivered and completed.</p>
<?php endif ?>

<h2 class="account-subtitle" id="payouts">Get paid</h2>
<div class="form__grid form__grid--2 stylist-payout">
    <form class="form" method="post" action="<?= e(link_to('/my-account/stylist/withdraw')) ?>">
        <?= csrf_field() ?>
        <p>Paid to: <strong><?= e($method) ?></strong></p>
        <div class="field<?= $fe('amount') ? ' field--error' : '' ?>"><label for="f-amount">Amount ($)</label><input type="text" inputmode="decimal" id="f-amount" name="amount" value="<?= e(number_format($balance['withdrawable'] / 100, 2, '.', '')) ?>"<?= $canRequest ? '' : ' disabled' ?>><?php if ($fe('amount')): ?><p class="field__error"><?= e($fe('amount')) ?></p><?php endif ?>
            <p class="field__hint">Minimum <?= e(money($threshold)) ?>.<?= !$details ? ' Add your payout details first.' : ($open ? ' You have a request in progress.' : '') ?></p></div>
        <button class="btn btn--dark" type="submit"<?= $canRequest ? '' : ' disabled' ?>>Request payout</button>
        <?php if ($requests): ?>
        <ul class="plain-list">
            <?php foreach ($requests as $q): ?><li><?= e(date('M j', strtotime($q['created_at']))) ?> · <?= e(money((int) $q['amount_cents'])) ?> · <?= e(ucfirst($q['status'])) ?><?php if ($q['status'] === 'pending'): ?> <button class="link-button" type="submit" form="cancel-<?= (int) $q['id'] ?>">Cancel</button><?php endif ?><?= $q['admin_note'] ? '<br><small>' . e($q['admin_note']) . '</small>' : '' ?></li><?php endforeach ?>
        </ul>
        <?php endif ?>
    </form>
    <form class="form" method="post" action="<?= e(link_to('/my-account/stylist/payout')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="f-method">Payout method</label><select id="f-method" name="method" data-payout-method><option value="paypal"<?= ($stylist['payout_method'] ?? 'paypal') === 'paypal' ? ' selected' : '' ?>>PayPal</option><option value="bank"<?= $stylist['payout_method'] === 'bank' ? ' selected' : '' ?>>Bank transfer</option></select></div>
        <div data-payout-panel="paypal">
            <div class="field<?= $fe('paypal_email') ? ' field--error' : '' ?>"><label for="f-pp">PayPal email</label><input type="email" id="f-pp" name="paypal_email" value="<?= e((string) ($details['email'] ?? '')) ?>"></div>
        </div>
        <div data-payout-panel="bank" hidden>
            <div class="field"><label for="f-an">Account holder</label><input type="text" id="f-an" name="account_name" value="<?= e((string) ($details['account_name'] ?? '')) ?>" maxlength="100"></div>
            <div class="field"><label for="f-bn">Bank</label><input type="text" id="f-bn" name="bank_name" value="<?= e((string) ($details['bank_name'] ?? '')) ?>" maxlength="100"></div>
            <div class="form__grid form__grid--2">
                <div class="field<?= $fe('routing_number') ? ' field--error' : '' ?>"><label for="f-rn">Routing number</label><input type="text" id="f-rn" name="routing_number" inputmode="numeric" maxlength="9" value="<?= e((string) ($details['routing_number'] ?? '')) ?>" autocomplete="off"></div>
                <div class="field<?= $fe('account_number') ? ' field--error' : '' ?>"><label for="f-acc">Account number</label><input type="text" id="f-acc" name="account_number" inputmode="numeric" maxlength="17" placeholder="<?= !empty($details['account_number']) ? '••••' . e(substr((string) $details['account_number'], -4)) : '' ?>" autocomplete="off"></div>
            </div>
            <div class="field"><label for="f-at">Account type</label><select id="f-at" name="account_type"><option value="checking">Checking</option><option value="savings"<?= ($details['account_type'] ?? '') === 'savings' ? ' selected' : '' ?>>Savings</option></select></div>
        </div>
        <button class="btn btn--outline" type="submit">Save payout details</button>
        <p class="field__hint">Stored encrypted. Only our finance team can see them when paying you.</p>
    </form>
</div>
<?php foreach (array_filter($requests, static fn ($r) => $r['status'] === 'pending') as $q): ?>
<form id="cancel-<?= (int) $q['id'] ?>" method="post" action="<?= e(link_to('/my-account/stylist/withdraw/' . $q['id'] . '/cancel')) ?>"><?= csrf_field() ?></form>
<?php endforeach ?>

<?php if ($ledger): ?>
<h2 class="account-subtitle">Activity</h2>
<table class="account-table">
    <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $l): ?><tr><td data-label="Date"><?= e(date('M j, Y', strtotime($l['created_at']))) ?></td><td data-label="Description"><?= e($l['description']) ?><?= $l['available_at'] === null ? ' <small>(pending)</small>' : '' ?></td><td data-label="Amount" class="<?= (int) $l['amount_cents'] < 0 ? 'neg' : 'pos' ?>"><?= e(money((int) $l['amount_cents'])) ?></td></tr><?php endforeach ?>
    </tbody>
</table>
<?php endif ?>
