<?php /** Side cart contents (also returned as JSON after "Add to cart"). */ $lines = array_merge(...array_map(static fn ($p) => $p['lines'], $summary['packages'] ?: [['lines' => []]])); ?>
<?php if (!$lines): ?>
    <p class="drawer__empty">Your cart is currently empty.</p>
    <a class="btn btn--gold btn--block" href="<?= e(link_to('/shop/')) ?>">Return to shop</a>
<?php else: ?>
    <ul class="drawer__items">
        <?php foreach ($lines as $l): ?>
            <li class="drawer__item">
                <a href="<?= e(link_to('/product/' . $l['slug'] . '/')) ?>" class="drawer__thumb"><?= image($l['image_media_id'], ['alt' => '', 'max' => 80, 'sizes' => '64px']) ?></a>
                <div class="drawer__info">
                    <a class="drawer__name" href="<?= e(link_to('/product/' . $l['slug'] . '/')) ?>"><?= e($l['name']) ?></a>
                    <?php if ($l['variant_label']): ?><span class="drawer__meta"><?= e($l['variant_label']) ?></span><?php endif ?>
                    <span class="drawer__meta"><?= (int) $l['quantity'] ?> × <?= e(money($l['unit_cents'])) ?></span>
                </div>
                <form method="post" action="<?= e(link_to('/cart/remove')) ?>" data-cart-remove>
                    <?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= (int) $l['variant_id'] ?>">
                    <button type="submit" class="drawer__remove" aria-label="Remove <?= e($l['name']) ?>"><?= icon('close') ?></button>
                </form>
            </li>
        <?php endforeach ?>
    </ul>
    <p class="drawer__subtotal"><span>Subtotal:</span> <strong><?= e(money($summary['totals']['subtotal_cents'])) ?></strong></p>
    <div class="drawer__actions">
        <a class="btn btn--outline-light btn--block" href="<?= e(link_to('/cart/')) ?>">View cart</a>
        <a class="btn btn--gold btn--block" href="<?= e(link_to('/checkout/')) ?>">Checkout</a>
    </div>
<?php endif ?>
