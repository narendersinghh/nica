<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\StylistsController;
$this->layout('layouts/panel');
$link = static fn (?string $st) => link_to('/admin/stylists/') . ($st ? '?status=' . $st : '');
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filter by status">
        <a href="<?= e($link(null)) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>>All<span><?= array_sum($counts) ?></span></a>
        <?php foreach (StylistsController::STATUSES as $k => $l): if (!$counts[$k] && $status !== $k) continue; ?><a href="<?= e($link($k)) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><span><?= (int) $counts[$k] ?></span></a><?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/admin/stylists/')) ?>" role="search"><?= picon('search') ?><label class="sr-only" for="ss">Search stylists</label><input id="ss" type="search" name="s" value="<?= e($search) ?>" placeholder="Name, email, code or salon"></form>
</div>
<section class="card card--flush">
    <?php if (!$rows): ?><div class="empty"><?= picon('users') ?>No stylists yet. They apply from “Stylist Program” in My Account, linked from the For Stylists page.</div><?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Stylist</th><th>Code</th><th>Status</th><th class="right">Visits (30d)</th><th class="right">Orders</th><th class="right">Sales</th><th class="right">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/admin/stylists/' . $r['id'] . '/')) ?>"><strong><?= e(trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['email']) ?></strong></a><div class="muted small"><?= e(trim(($r['salon_name'] ?? '') . ' · ' . $r['city'] . ', ' . $r['state'], ' ·')) ?></div></td>
                <td data-label="Code"><code><?= e($r['code']) ?></code></td>
                <td data-label="Status"><span class="pill pill--<?= e($r['status']) ?>"><?= e(StylistsController::STATUSES[$r['status']]) ?></span></td>
                <td data-label="Visits" class="right num"><?= (int) $r['clicks'] ?></td>
                <td data-label="Orders" class="right num"><?= (int) $r['orders'] ?></td>
                <td data-label="Sales" class="right num"><?= e(money((int) $r['sales'])) ?></td>
                <td data-label="Balance" class="right num"><?= e(money((int) $r['balance'])) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>
