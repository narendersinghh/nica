<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\VendorOrders;
$this->layout('layouts/panel');
$filterLink = static fn (?string $s) => link_to('/dashboard/orders/') . ($s ? '?status=' . $s : '');
$shown = ['processing', 'shipped', 'completed', 'on-hold', 'pending', 'cancelled', 'refunded'];
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filter by status">
        <a href="<?= e($filterLink(null)) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>>All<span><?= array_sum($counts) ?></span></a>
        <?php foreach ($shown as $key): if (!$counts[$key] && $status !== $key) continue; ?>
            <a href="<?= e($filterLink($key)) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>><?= e($key === 'processing' ? 'To ship' : VendorOrders::STATUS_LABELS[$key]) ?><span><?= (int) $counts[$key] ?></span></a>
        <?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/dashboard/orders/')) ?>" role="search">
        <?= picon('search') ?>
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
        <label class="sr-only" for="order-search">Search orders</label>
        <input id="order-search" type="search" name="s" value="<?= e($search) ?>" placeholder="Order number, email or last name">
    </form>
</div>

<section class="card card--flush">
    <?php if (!$list['rows']): ?>
        <div class="empty"><?= picon('receipt') ?><?= $search !== '' || $status ? 'No orders match.' : 'No orders yet.' ?></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Items</th><th>Status</th><th class="right">Total</th><th class="right">Your earning</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $o): $b = json_decode($o['billing_address'], true) ?: []; ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/dashboard/orders/' . $o['id'] . '/')) ?>"><strong>#<?= e($o['number']) ?></strong></a></td>
                <td data-label="Date" class="nowrap"><?= e(gmdate('M j, Y', strtotime($o['ordered_at']))) ?></td>
                <td data-label="Customer"><?= e(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''))) ?></td>
                <td data-label="Items" class="num"><?= (int) $o['item_count'] ?></td>
                <td data-label="Status"><?= $this->insert('vendor/_status', ['status' => $o['status']]) ?><?php if ($o['payout_mode'] === 'stripe_connect'): ?> <span class="pill" title="Paid to your Stripe account"><?= picon('bolt') ?>Stripe</span><?php endif ?></td>
                <td data-label="Total" class="right num"><?= e(money((int) $o['total_cents'])) ?></td>
                <td data-label="Your earning" class="right num"><strong><?= e(money((int) $o['vendor_earning_cents'])) ?></strong></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->insert('vendor/_pager', ['page' => $list['page'], 'pages' => $list['pages'], 'base' => '/dashboard/orders/', 'query' => array_filter(['status' => $status, 's' => $search])]) ?>
    <?php endif ?>
</section>
