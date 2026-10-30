<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\VendorsController;
$this->layout('layouts/panel');
$link = static fn (?string $s) => link_to('/admin/vendors/') . ($s ? '?status=' . $s : '');
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filter by status">
        <a href="<?= e($link(null)) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>>All<span><?= array_sum($counts) ?></span></a>
        <?php foreach (VendorsController::STATUSES as $k => $l): if (!$counts[$k] && $status !== $k) continue; ?>
            <a href="<?= e($link($k)) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><span><?= (int) $counts[$k] ?></span></a>
        <?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/admin/vendors/')) ?>" role="search"><?= picon('search') ?><label class="sr-only" for="vs">Search vendors</label><input id="vs" type="search" name="s" value="<?= e($search) ?>" placeholder="Store name or email"></form>
</div>
<section class="card card--flush">
    <?php if (!$vendors): ?><div class="empty"><?= picon('store') ?>No vendors match.</div><?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Store</th><th>Status</th><th>Owner</th><th class="right">Products</th><th class="right">Sales</th><th class="right">Balance</th><th>Commission</th></tr></thead>
        <tbody>
        <?php foreach ($vendors as $v): ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/admin/vendors/' . $v['id'] . '/')) ?>"><strong><?= e($v['store_name']) ?></strong></a><?php if ((int) $v['is_premier_partner']): ?> <span class="pill pill--pending">Premier #<?= (int) $v['premier_number'] ?></span><?php endif ?><div class="muted small">Since <?= e(gmdate('M Y', strtotime($v['created_at']))) ?></div></td>
                <td data-label="Status"><span class="pill pill--<?= e($v['status']) ?>"><?= e(VendorsController::STATUSES[$v['status']]) ?></span><?= (int) $v['vacation_mode'] ? ' <span class="pill">Vacation</span>' : '' ?></td>
                <td data-label="Owner"><?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?><div class="muted small"><?= e((string) $v['email']) ?></div></td>
                <td data-label="Products" class="right num"><?= (int) $v['products'] ?></td>
                <td data-label="Sales" class="right num"><?= e(money((int) $v['sales'])) ?></td>
                <td data-label="Balance" class="right num"><?= e(money((int) $v['balance'])) ?></td>
                <td data-label="Commission"><?= $v['commission_rate'] !== null ? e(rtrim(rtrim($v['commission_rate'], '0'), '.')) . '%' : '<span class="muted">Default</span>' ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>
