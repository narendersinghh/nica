<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-cart']);
$packages = $summary['packages'];
$totals = $summary['totals'];
?>
<section class="page-banner">
    <div class="container">
        <p class="eyebrow eyebrow--light"><?= icon('cart') ?><span>Review your items before checkout</span></p>
        <h1>Cart</h1>
    </div>
</section>
<section class="section-pad section--soft cart-page">
    <div class="container">
        <?php foreach ($notices as $n): ?><div class="notice" role="status"><?= e($n) ?></div><?php endforeach ?>
        <?php if (!$packages): ?>
            <div class="cart-empty">
                <p>Your cart is currently empty.</p>
                <a class="btn btn--dark" href="<?= e(link_to('/shop/')) ?>">Return to shop <?= icon('arrow-right') ?></a>
            </div>
        <?php else: ?>
        <div class="cart-layout">
            <div class="cart-panel">
                <form method="post" action="<?= e(link_to('/cart/update')) ?>" id="cart-form">
                    <?= csrf_field() ?>
                    <table class="cart-table">
                        <thead><tr><th scope="col" colspan="2">Product</th><th scope="col">Price</th><th scope="col">Quantity</th><th scope="col">Subtotal</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
                        <?php foreach ($packages as $pkg): ?>
                            <tbody>
                                <tr class="cart-table__vendor"><th colspan="6" scope="rowgroup">Sold by <?= e($pkg['vendor_name']) ?></th></tr>
                                <?php foreach ($pkg['lines'] as $l): ?>
                                    <tr>
                                        <td class="cart-table__thumb"><a href="<?= e(link_to('/product/' . $l['slug'] . '/')) ?>"><?= image($l['image_media_id'], ['alt' => '', 'max' => 100, 'sizes' => '72px']) ?></a></td>
                                        <td class="cart-table__name" data-label="Product">
                                            <a href="<?= e(link_to('/product/' . $l['slug'] . '/')) ?>"><?= e($l['name']) ?></a>
                                            <?php if ($l['variant_label']): ?><span><?= e($l['variant_label']) ?></span><?php endif ?>
                                        </td>
                                        <td data-label="Price"><?php if ($l['on_sale']): ?><del><?= e(money($l['regular_cents'])) ?></del> <?php endif ?><?= e(money($l['unit_cents'])) ?></td>
                                        <td data-label="Quantity"><label class="visually-hidden" for="q<?= (int) $l['variant_id'] ?>">Quantity</label><input class="qty" id="q<?= (int) $l['variant_id'] ?>" type="number" name="quantity[<?= (int) $l['variant_id'] ?>]" value="<?= (int) $l['quantity'] ?>" min="0" max="<?= (int) $l['max_quantity'] ?>" inputmode="numeric"></td>
                                        <td data-label="Subtotal"><?= e(money($l['subtotal_cents'])) ?></td>
                                        <td><button type="submit" form="remove-<?= (int) $l['variant_id'] ?>" class="cart-table__remove" aria-label="Remove <?= e($l['name']) ?>"><?= icon('close') ?></button></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        <?php endforeach ?>
                    </table>
                </form>
                <?php foreach ($packages as $pkg): foreach ($pkg['lines'] as $l): ?>
                    <form method="post" action="<?= e(link_to('/cart/remove')) ?>" id="remove-<?= (int) $l['variant_id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= (int) $l['variant_id'] ?>"></form>
                <?php endforeach; endforeach ?>
                <div class="cart-actions">
                    <form method="post" action="<?= e(link_to('/cart/coupon')) ?>" class="coupon-form">
                        <?= csrf_field() ?>
                        <label class="visually-hidden" for="coupon_code">Coupon code</label>
                        <input id="coupon_code" type="text" name="coupon_code" placeholder="Coupon code" autocomplete="off" maxlength="64">
                        <button class="btn btn--outline" type="submit">Apply coupon</button>
                    </form>
                    <button class="btn btn--dark" type="submit" form="cart-form">Update Cart</button>
                </div>
                <?php if ($couponError): ?><p class="field__error" role="alert"><?= e($couponError) ?></p><?php endif ?>
            </div>
            <aside class="cart-panel cart-totals">
                <h2>Cart Totals</h2>
                <?= $this->insert('cart/totals', ['summary' => $summary, 'return' => 'cart']) ?>
                <a class="btn btn--gold btn--block" href="<?= e(link_to('/checkout/')) ?>">Proceed to Checkout</a>
            </aside>
        </div>
        <?php endif ?>
    </div>
</section>
