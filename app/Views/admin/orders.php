<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\OrdersController;
$this->layout('layouts/panel');
$link = static fn (?string $s) => link_to('/admin/orders/') . ($s ? '?status=' . $s : '');
?>
<?php $this->start('actions') ?>
<?php if (App\Http\Middleware\AdminAccess::can('finance')): ?>
<a class="btn btn--ghost" href="<?= e(link_to('/admin/orders/export') . '?' . http_build_query(array_filter(['status' => $status, 's' => $search]))) ?>"><?= picon('download') ?>Export CSV</a>
<?php endif ?>
<?php $this->stop() ?>
<div class="toolbar">
    <nav class="chips" aria-label="Filter by status">
        <a href="<?= e($link(null)) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>>All<span><?= array_sum($counts) ?></span></a>
        <?php foreach (OrdersController::STATUSES as $k => $l): if (!$counts[$k] && $status !== $k) continue; ?>
            <a href="<?= e($link($k)) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><span><?= (int) $counts[$k] ?></span></a>
        <?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/admin/orders/')) ?>" role="search"><?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?><?= picon('search') ?><label class="sr-only" for="os">Search orders</label><input id="os" type="search" name="s" value="<?= e($search) ?>" placeholder="Order number, email or last name"></form>
</div>
<section class="card card--flush">
    <?php if (!$rows): ?><div class="empty"><?= picon('receipt') ?>No orders match.</div><?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Vendors</th><th>Status</th><th class="right">Total</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): $b = json_decode($o['billing_address'], true) ?: []; ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/admin/orders/' . $o['id'] . '/')) ?>"><strong>#<?= e($o['number']) ?></strong></a></td>
                <td data-label="Date" class="nowrap"><?= e(gmdate('M j, Y', strtotime($o['created_at']))) ?></td>
                <td data-label="Customer"><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?><div class="muted small"><?= e($o['email']) ?></div></td>
                <td data-label="Vendors" class="small"><?= e((string) $o['vendors']) ?></td>
                <td data-label="Status"><?= $this->insert('admin/_order-status', ['status' => $o['status']]) ?></td>
                <td data-label="Total" class="right num"><?= e(money((int) $o['total_cents'])) ?><?php if ((int) $o['refunded_cents']): ?><div class="small trend--down">−<?= e(money((int) $o['refunded_cents'])) ?></div><?php endif ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->insert('vendor/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/orders/', 'query' => array_filter(['status' => $status, 's' => $search])]) ?>
    <?php endif ?>
</section>
