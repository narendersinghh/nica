<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$now = gmdate('Y-m-d H:i:s');
?>
<?php $this->start('actions') ?>
<a class="btn" href="<?= e(link_to('/dashboard/coupons/new/')) ?>"><?= picon('plus') ?>Add coupon</a>
<?php $this->stop() ?>
<p class="muted">Store coupons work only on your products and the discount comes out of your earnings.</p>
<section class="card card--flush">
    <?php if (!$coupons): ?>
        <div class="empty"><?= picon('tag') ?><p>No coupons yet. Create one to run a sale or reward loyal customers.</p><a class="btn" href="<?= e(link_to('/dashboard/coupons/new/')) ?>"><?= picon('plus') ?>Add coupon</a></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Code</th><th>Discount</th><th>Used</th><th>Expires</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($coupons as $c): $expired = $c['expires_at'] && $c['expires_at'] < $now; ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/dashboard/coupons/' . $c['id'] . '/')) ?>"><strong><?= e($c['code']) ?></strong></a><?php if ($c['description']): ?><div class="muted small"><?= e($c['description']) ?></div><?php endif ?></td>
                <td data-label="Discount" class="num"><?= $c['type'] === 'percent' ? e(rtrim(rtrim($c['amount'], '0'), '.')) . '%' : e(money((int) round((float) $c['amount'] * 100))) . ($c['type'] === 'fixed_product' ? ' per item' : '') ?><?= (int) $c['free_shipping'] ? ' + free shipping' : '' ?></td>
                <td data-label="Used" class="num"><?= (int) $c['usage_count'] ?><?= $c['usage_limit'] ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
                <td data-label="Expires" class="nowrap"><?= $c['expires_at'] ? e(gmdate('M j, Y', strtotime($c['expires_at']))) : '<span class="muted">Never</span>' ?></td>
                <td data-label="Status"><?= $this->insert('vendor/_status', ['status' => $expired ? 'cancelled' : $c['status'], 'label' => $expired ? 'Expired' : ucfirst($c['status'])]) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>
