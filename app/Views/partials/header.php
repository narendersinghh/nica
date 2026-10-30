<?php /** @var App\Core\Template $this */ ?>
<header class="site-header<?= $overlay ? ' site-header--overlay' : '' ?>">
    <div class="announcement"><p><?= e($site['announcement']) ?></p></div>
    <div class="site-header__bar">
        <div class="site-header__inner">
            <button class="site-header__toggle" type="button" aria-label="Open menu" aria-controls="mobile-menu" aria-expanded="false" data-menu-open>
                <?= icon('bars') ?>
            </button>
            <a class="site-header__logo" href="<?= e(link_to('/')) ?>" aria-label="<?= e(config('app.name')) ?> home">
                <?= image($site['logo'], ['alt' => config('app.name'), 'max' => 85, 'sizes' => '85px', 'loading' => 'eager']) ?>
            </a>
            <nav class="site-nav" aria-label="Main">
                <ul>
                    <?php foreach ($site['menu'] as $item): ?>
                        <li><a href="<?= e(link_to($item['url'])) ?>"<?= is_current($item['url']) ? ' class="is-active" aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li>
                    <?php endforeach ?>
                </ul>
            </nav>
            <a class="site-header__account" href="<?= e(link_to('/my-account/')) ?>" aria-label="My account"><?= icon('user') ?></a>
            <?php
            // Only touch the database when the visitor actually has a cart.
            $cartCount = 0;
            $cartSubtotal = 0;
            if (isset($_COOKIE[App\Services\Cart\Cart::COOKIE]) || app()->auth()->check()) {
                $cartLines = app()->cart()->lines();
                $cartCount = array_sum(array_column($cartLines, 'quantity'));
                $cartSubtotal = array_sum(array_map(static fn ($l) => $l['unit_cents'] * $l['quantity'], $cartLines));
            }
            ?>
            <a class="site-header__cart" href="<?= e(link_to('/cart/')) ?>" aria-label="Cart, <?= (int) $cartCount ?> items" data-cart-toggle aria-controls="cart-drawer">
                <span class="site-header__cart-total" data-cart-total><?= e(money($cartSubtotal)) ?></span>
                <span class="site-header__cart-icon"><?= icon('cart') ?><span class="site-header__cart-count" data-cart-count><?= (int) $cartCount ?></span></span>
            </a>
        </div>
    </div>
</header>
