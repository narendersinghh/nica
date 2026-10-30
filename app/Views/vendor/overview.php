<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$change = (int) $sales['last_month'] > 0 ? ((int) $sales['this_month'] - (int) $sales['last_month']) / (int) $sales['last_month'] * 100 : null;
$done = count(array_filter($checklist, static fn ($c) => $c[1]));
$max = max(1, max($daily));
$days = array_keys($daily);
?>
<?php $this->start('actions') ?>
<a class="btn" href="<?= e(link_to('/dashboard/products/new/')) ?>"><?= picon('plus') ?>Add product</a>
<?php $this->stop() ?>

<?php if ($toShip > 0): ?>
<div class="alert alert--info"><?= picon('truck') ?><div><strong><?= $toShip ?> <?= $toShip === 1 ? 'order is' : 'orders are' ?> waiting to ship.</strong> <a href="<?= e(link_to('/dashboard/orders/?status=processing')) ?>">Ship now</a></div></div>
<?php endif ?>

<div class="stats">
    <div class="stat">
        <div class="stat__label">Sales this month</div>
        <div class="stat__value"><?= e(money((int) $sales['this_month'])) ?></div>
        <div class="stat__meta"><?php if ($change !== null): ?><span class="trend--<?= $change >= 0 ? 'up' : 'down' ?>"><?= $change >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($change), 0)) ?>%</span> vs last month<?php else: ?>Last month <?= e(money((int) $sales['last_month'])) ?><?php endif ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Orders this month</div>
        <div class="stat__value"><?= (int) $sales['orders_month'] ?></div>
        <div class="stat__meta"><?= (int) $sales['orders_total'] ?> all time</div>
    </div>
    <div class="stat">
        <div class="stat__label">Earned this month</div>
        <div class="stat__value"><?= e(money((int) $sales['earned_month'])) ?></div>
        <div class="stat__meta">After commission</div>
    </div>
    <div class="stat stat--accent">
        <div class="stat__label">Available balance</div>
        <div class="stat__value"><?= e(money($balance['withdrawable'])) ?></div>
        <div class="stat__meta"><?= e(money($balance['pending'])) ?> pending · <a href="<?= e(link_to('/dashboard/withdraw/')) ?>">Withdraw</a></div>
    </div>
</div>

<div class="grid grid--main">
    <div>
        <section class="card">
            <div class="card__head"><h2>Sales, last 30 days</h2><span class="muted small"><?= e(money(array_sum($daily))) ?></span></div>
            <svg class="chart-svg" viewBox="0 0 300 120" preserveAspectRatio="none" role="img" aria-label="Daily sales for the last 30 days">
                <?php foreach (array_values($daily) as $i => $cents): $h = $cents > 0 ? max(2, round($cents / $max * 112, 1)) : 1; ?>
                    <rect x="<?= $i * 10 + 1 ?>" y="<?= 120 - $h ?>" width="8" height="<?= $h ?>" rx="1.5" fill="<?= $cents > 0 ? '#DFB467' : '#EAE3DA' ?>"><title><?= e(gmdate('M j', strtotime($days[$i])) . ': ' . money($cents)) ?></title></rect>
                <?php endforeach ?>
            </svg>
            <div class="chart-axis"><span><?= e(gmdate('M j', strtotime($days[0]))) ?></span><span>Today</span></div>
        </section>

        <section class="card card--flush">
            <div class="card__head"><h2>Recent orders</h2><a class="small" href="<?= e(link_to('/dashboard/orders/')) ?>">View all</a></div>
            <?php if (!$recent): ?>
                <div class="empty"><?= picon('receipt') ?>No orders yet. They will appear here as soon as a customer buys from you.</div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--stack">
                <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="right">Your earning</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $o): $b = json_decode($o['billing_address'], true) ?: []; ?>
                    <tr>
                        <td data-label="Order"><a href="<?= e(link_to('/dashboard/orders/' . $o['id'] . '/')) ?>">#<?= e($o['number']) ?></a><div class="muted small"><?= e(gmdate('M j, Y', strtotime($o['created_at']))) ?></div></td>
                        <td data-label="Customer"><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?></td>
                        <td data-label="Status"><?= $this->insert('vendor/_status', ['status' => $o['status']]) ?></td>
                        <td data-label="Your earning" class="right num"><?= e(money((int) $o['vendor_earning_cents'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </section>
    </div>

    <div>
        <?php if ($done < count($checklist)): ?>
        <section class="card">
            <div class="card__head"><h2>Get your store ready</h2><span class="muted small"><?= $done ?>/<?= count($checklist) ?></span></div>
            <div class="progress"><span data-width="<?= round($done / count($checklist) * 100) ?>"></span></div>
            <ul class="checklist">
                <?php foreach ($checklist as [$label, $isDone, $link]): ?>
                    <li class="<?= $isDone ? 'is-done' : '' ?>"><span class="tick"><?= $isDone ? picon('check') : '' ?></span><span><?= e($label) ?></span><?php if (!$isDone && $link): ?><a href="<?= e(link_to($link)) ?>">Start</a><?php endif ?></li>
                <?php endforeach ?>
            </ul>
        </section>
        <?php endif ?>

        <section class="card">
            <div class="card__head"><h2>Products</h2><a class="small" href="<?= e(link_to('/dashboard/products/')) ?>">Manage</a></div>
            <ul class="list-rows">
                <li><span>Online</span><strong><?= (int) $productCounts['published'] ?></strong></li>
                <li><span>Drafts</span><strong><?= (int) $productCounts['draft'] ?></strong></li>
                <?php if ($productCounts['pending'] || $productCounts['rejected']): ?>
                <li><span>In review / needs changes</span><strong><?= (int) $productCounts['pending'] ?> / <?= (int) $productCounts['rejected'] ?></strong></li>
                <?php endif ?>
            </ul>
        </section>

        <section class="card">
            <div class="card__head"><h2>Low stock</h2></div>
            <?php if (!$lowStock): ?>
                <p class="muted small">Nothing is running low.</p>
            <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($lowStock as $s): ?>
                    <li><a href="<?= e(link_to('/dashboard/products/' . $s['product_id'] . '/')) ?>"><?= e($s['name']) ?><?php if ($s['label']): ?> <span class="muted">· <?= e($s['label']) ?></span><?php endif ?></a><span class="pill pill--<?= (int) $s['stock_qty'] > 0 ? 'pending' : 'cancelled' ?>"><?= (int) $s['stock_qty'] ?> left</span></li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>

        <section class="card">
            <div class="card__head"><h2>Getting paid</h2></div>
            <?php if ($connected): ?>
                <p class="small"><?= picon('bolt') ?> <strong>Stripe connected.</strong> Your share of each order is paid to your Stripe account automatically.</p>
            <?php else: ?>
                <p class="small muted">Payouts are sent on request once your balance reaches <?= e(money($threshold)) ?>. Connect Stripe to get paid automatically on every order.</p>
                <a class="btn btn--ghost btn--sm" href="<?= e(link_to('/dashboard/settings/payment/')) ?>">Payout settings</a>
            <?php endif ?>
        </section>
    </div>
</div>
