<?php $labels = ['processing' => 'Processing', 'on-hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed', 'pending' => 'Pending payment']; ?>
<table class="account-table">
    <thead><tr><th scope="col">Order</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Total</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
        <tr>
            <td data-label="Order"><a href="<?= e(link_to('/my-account/view-order/' . $o['number'] . '/')) ?>">#<?= e($o['number']) ?></a></td>
            <td data-label="Date"><time datetime="<?= e(substr($o['created_at'], 0, 10)) ?>"><?= e(date('F j, Y', strtotime($o['created_at']))) ?></time></td>
            <td data-label="Status"><span class="status status--<?= e($o['status']) ?>"><?= e($labels[$o['status']] ?? $o['status']) ?></span></td>
            <td data-label="Total"><?= e(money((int) $o['total_cents'])) ?><?= isset($o['item_count']) ? ' for ' . (int) $o['item_count'] . ' item' . ((int) $o['item_count'] === 1 ? '' : 's') : '' ?></td>
            <td><a class="btn btn--sm btn--outline" href="<?= e(link_to('/my-account/view-order/' . $o['number'] . '/')) ?>">View</a></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
