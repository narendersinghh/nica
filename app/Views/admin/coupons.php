<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\CouponsController;
$this->layout('layouts/panel');
$now = gmdate('Y-m-d H:i:s');
?>
<?php $this->start('actions') ?><a class="btn" href="<?= e(link_to('/admin/coupons/new/')) ?>"><?= picon('plus') ?>New coupon</a><?php $this->stop() ?>
<section class="card card--flush">
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Code</th><th>Discount</th><th>Owner</th><th>Who pays</th><th class="right">Used</th><th>Expires</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): $expired = $c['expires_at'] && $c['expires_at'] < $now; ?>
            <tr>
                <td class="prod-col"><a href="<?= e(link_to('/admin/coupons/' . $c['id'] . '/')) ?>"><strong><?= e($c['code']) ?></strong></a><?= $c['description'] ? '<div class="muted small">' . e($c['description']) . '</div>' : '' ?></td>
                <td data-label="Discount" class="num"><?= $c['type'] === 'percent' ? e(rtrim(rtrim($c['amount'], '0'), '.')) . '%' : e(money((int) round((float) $c['amount'] * 100))) . ($c['type'] === 'fixed_product' ? ' / item' : '') ?><?= (int) $c['free_shipping'] ? ' <span class="pill">Free shipping</span>' : '' ?></td>
                <td data-label="Owner"><?= $c['store_name'] ? e($c['store_name']) : 'Marketplace' ?></td>
                <td data-label="Who pays" class="small"><?= e(CouponsController::BEARERS[$c['cost_bearer']] ?? $c['cost_bearer']) ?></td>
                <td data-label="Used" class="right num"><?= (int) $c['usage_count'] ?><?= $c['usage_limit'] ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
                <td data-label="Expires" class="nowrap small"><?= $c['expires_at'] ? e(gmdate('M j, Y', strtotime($c['expires_at']))) : '—' ?></td>
                <td data-label="Status"><span class="pill pill--<?= $expired ? 'cancelled' : e($c['status']) ?>"><?= $expired ? 'Expired' : e(ucfirst($c['status'])) ?></span></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
