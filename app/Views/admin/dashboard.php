<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$pct = static fn (int $now, int $before) => $before > 0 ? ($now - $before) / $before * 100 : null;
$gmvChange = $pct((int) $kpi['gmv'], (int) $kpi['gmv_last']);
$aov = (int) $kpi['orders'] > 0 ? intdiv((int) $kpi['gmv'], (int) $kpi['orders']) : 0;
$max = max(1, max($daily));
$days = array_keys($daily);
?>
<?php if ($needs2fa): ?>
<div class="alert alert--warn"><?= picon('shield') ?><div><strong>Protect the admin panel:</strong> turn on two-factor authentication for your account. It is required on the live site. <a href="<?= e(link_to('/my-account/security/')) ?>">Set it up</a></div></div>
<?php endif ?>

<div class="stats">
    <div class="stat"><div class="stat__label">Sales this month</div><div class="stat__value"><?= e(money((int) $kpi['gmv'])) ?></div>
        <div class="stat__meta"><?php if ($gmvChange !== null): ?><span class="trend--<?= $gmvChange >= 0 ? 'up' : 'down' ?>"><?= $gmvChange >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($gmvChange))) ?>%</span> vs last month<?php else: ?>Last month <?= e(money((int) $kpi['gmv_last'])) ?><?php endif ?></div></div>
    <div class="stat"><div class="stat__label">Orders this month</div><div class="stat__value"><?= (int) $kpi['orders'] ?></div><div class="stat__meta"><?= (int) $kpi['orders_last'] ?> last month</div></div>
    <div class="stat"><div class="stat__label">Average order</div><div class="stat__value"><?= e(money($aov)) ?></div><div class="stat__meta">Including tax &amp; shipping</div></div>
    <div class="stat stat--accent"><div class="stat__label">Commission this month</div><div class="stat__value"><?= e(money($commission)) ?></div><div class="stat__meta">Marketplace revenue</div></div>
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
            <div class="card__head"><h2>Latest orders</h2><a class="small" href="<?= e(link_to('/admin/orders/')) ?>">All orders</a></div>
            <div class="table-wrap"><table class="table table--stack">
                <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="right">Total</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $o): $b = json_decode($o['billing_address'], true) ?: []; ?>
                    <tr>
                        <td class="prod-col"><a href="<?= e(link_to('/admin/orders/' . $o['id'] . '/')) ?>"><strong>#<?= e($o['number']) ?></strong></a> <span class="muted small"><?= e(gmdate('M j', strtotime($o['created_at']))) ?></span></td>
                        <td data-label="Customer"><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: $o['email']) ?></td>
                        <td data-label="Status"><?= $this->insert('admin/_order-status', ['status' => $o['status']]) ?></td>
                        <td data-label="Total" class="right num"><?= e(money((int) $o['total_cents'])) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
        </section>
    </div>
    <div>
        <section class="card">
            <h2>Needs attention</h2>
            <ul class="list-rows">
                <?php foreach ($queues as [$label, $count, $link, $ico]): ?>
                    <li><a href="<?= e(link_to($link)) ?>"><?= picon($ico) ?> <?= e($label) ?></a><span class="pill<?= $count ? ' pill--pending' : '' ?>"><?= (int) $count ?></span></li>
                <?php endforeach ?>
                <li><a href="<?= e(link_to('/admin/products/?stock=low')) ?>"><?= picon('box') ?> Low-stock variations</a><span class="pill<?= $lowStock ? ' pill--pending' : '' ?>"><?= (int) $lowStock ?></span></li>
            </ul>
            <?php if ($payoutTotal): ?><p class="small muted">Open payout requests total <?= e(money($payoutTotal)) ?>.</p><?php endif ?>
        </section>
        <section class="card">
            <h2>Top vendors, 90 days</h2>
            <?php if (!$topVendors): ?><p class="small muted">No sales yet.</p><?php else: ?>
            <ul class="list-rows">
                <?php foreach ($topVendors as $v): ?><li><a href="<?= e(link_to('/admin/vendors/' . $v['id'] . '/')) ?>"><?= e($v['store_name']) ?></a><span class="num"><?= e(money((int) $v['sales'])) ?> <span class="muted small">· <?= (int) $v['orders'] ?></span></span></li><?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>
    </div>
</div>
