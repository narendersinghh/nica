<?php /** Totals table shared by cart and checkout. Shipping choice per vendor package. */ $totals = $summary['totals']; ?>
<table class="totals-table">
    <tr><th scope="row">Subtotal</th><td><?= e(money($totals['subtotal_cents'])) ?></td></tr>
    <?php foreach ($summary['coupons'] as $c): ?>
        <tr class="totals-table__coupon">
            <th scope="row">Coupon: <?= e($c['coupon']['code']) ?></th>
            <td>−<?= e(money($c['discount_cents'])) ?>
                <form method="post" action="<?= e(link_to('/cart/coupon/remove')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="coupon_code" value="<?= e($c['coupon']['code']) ?>"><input type="hidden" name="return" value="<?= e($return) ?>"><button type="submit" class="link-button">[Remove]</button></form>
            </td>
        </tr>
    <?php endforeach ?>
    <?php foreach ($summary['packages'] as $pkg): ?>
        <tr class="totals-table__shipping">
            <th scope="row">Shipping<?= count($summary['packages']) > 1 ? '<span>' . e($pkg['vendor_name']) . '</span>' : '' ?></th>
            <td>
                <?php if (count($pkg['shipping_options']) > 1): ?>
                    <form method="post" action="<?= e(link_to('/cart/shipping')) ?>" data-shipping-form>
                        <?= csrf_field() ?><input type="hidden" name="vendor_id" value="<?= (int) $pkg['vendor_id'] ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
                        <?php foreach ($pkg['shipping_options'] as $opt): ?>
                            <label class="ship-option"><input type="radio" name="method" value="<?= e($opt['key']) ?>"<?= $opt['key'] === $pkg['shipping_method'] ? ' checked' : '' ?> data-autosubmit-radio> <?= e($opt['title']) ?><?= $opt['cents'] ? ': ' . e(money($opt['cents'])) : '' ?></label>
                        <?php endforeach ?>
                        <noscript><button type="submit" class="link-button">Update</button></noscript>
                    </form>
                <?php else: ?>
                    <?= e($pkg['shipping_title']) ?><?= $pkg['shipping_cents'] ? ': ' . e(money($pkg['shipping_cents'])) : '' ?>
                <?php endif ?>
            </td>
        </tr>
    <?php endforeach ?>
    <?php if ($totals['tax_cents'] > 0): ?><tr><th scope="row">Tax</th><td><?= e(money($totals['tax_cents'])) ?></td></tr><?php endif ?>
    <?php if (!empty($totals['points_used'])): ?>
        <tr class="totals-table__coupon"><th scope="row">Points (<?= (int) $totals['points_used'] ?>)</th>
            <td>−<?= e(money((int) $totals['points_discount_cents'])) ?>
                <form method="post" action="<?= e(link_to('/cart/points')) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="remove" value="1"><input type="hidden" name="return" value="<?= e($return) ?>"><button type="submit" class="link-button">[Remove]</button></form></td></tr>
    <?php endif ?>
    <tr class="totals-table__total"><th scope="row">Total</th><td><strong><?= e(money($totals['total_cents'])) ?></strong></td></tr>
</table>
<?php if (($summary['points_balance'] ?? 0) > 0 && empty($totals['points_used'])): $rate = App\Services\Points::rate(); ?>
    <form method="post" action="<?= e(link_to('/cart/points')) ?>" class="points-form">
        <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
        <p>You have <strong><?= (int) $summary['points_balance'] ?> points</strong> (worth <?= e(money(App\Services\Points::value((int) $summary['points_balance']))) ?>). <?= (int) $rate ?> points = $1.</p>
        <label class="visually-hidden" for="points-<?= e($return) ?>">Points to use</label>
        <input id="points-<?= e($return) ?>" type="number" name="points" min="1" step="1" max="<?= (int) $summary['points_balance'] ?>" value="<?= (int) $summary['points_balance'] ?>">
        <button class="btn btn--outline btn--sm" type="submit">Apply points</button>
    </form>
<?php endif ?>
<p class="totals-note">Shipping to the United States. Tax is calculated at <?= e(rtrim(rtrim(number_format((float) App\Services\Settings::get('commerce.tax.rate'), 2), '0'), '.')) ?>%.</p>
