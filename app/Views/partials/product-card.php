<?php /** @var App\Core\Template $this */ $url = link_to('/product/' . $product['slug'] . '/'); ?>
<article class="product-card">
    <a class="product-card__image" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
        <?= image($product['featured_media_id'] ? (int) $product['featured_media_id'] : null, ['alt' => '', 'max' => 400, 'sizes' => '(max-width: 767px) 50vw, (max-width: 1024px) 33vw, 290px', 'class' => 'product-card__img']) ?>
        <?php if (!(int) $product['in_stock']): ?><span class="product-card__flag">Out of stock</span><?php endif ?>
    </a>
    <?php if ((int) $product['is_premier_partner']): ?>
        <?= image(config('catalog.premier_badge'), ['alt' => 'NiCa Premiere Partner', 'max' => 40, 'sizes' => '40px', 'class' => 'product-card__badge']) ?>
    <?php endif ?>
    <div class="product-card__body">
        <p class="product-card__vendor"><strong>By:</strong> <?= e($product['vendor_name']) ?></p>
        <h3 class="product-card__title"><a href="<?= e($url) ?>"><?= e($product['name']) ?></a></h3>
    </div>
    <div class="product-card__foot">
        <?= $this->insert('partials/price', ['product' => $product]) ?>
        <a class="product-card__cart" href="<?= e($url) ?>" aria-label="<?= $product['type'] === 'variable' ? 'Select options for' : 'View' ?> <?= e($product['name']) ?>"><?= icon('bag') ?></a>
    </div>
</article>
