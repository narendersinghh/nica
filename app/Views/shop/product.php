<?php
/** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-product']);
$url = link_to('/product/' . $product['slug'] . '/');
$shareUrl = rawurlencode($seo->canonical ?? '');
$shareTitle = rawurlencode($product['name']);
$variationAttributes = array_values(array_filter($attributes, static fn ($a) => $a['variation']));
$isVariable = $product['type'] === 'variable' && count($variants) > 1;
$single = !$isVariable ? ($variants[0] ?? null) : null;
$variantImages = [];
foreach ($variants as $v) {
    if ($v['image']) {
        $variantImages[$v['image']] = image_url($v['image'], 'large');
    }
}
$pickerData = [
    'variants' => $variants,
    'attributes' => array_map(static fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $variationAttributes),
    'images' => (object) $variantImages,
];
?>
<section class="page-banner page-banner--slim">
    <div class="container">
        <a class="eyebrow eyebrow--light" href="<?= e(link_to('/shop/')) ?>"><?= icon('chevron-left') ?><span>Back to shop</span></a>
    </div>
</section>

<section class="section-pad section--soft product">
    <div class="container product__inner">
        <div class="gallery" data-gallery>
            <div class="gallery__main">
                <?php foreach ($images as $i => $mediaId): ?>
                    <button type="button" class="gallery__slide<?= $i === 0 ? ' is-active' : '' ?>" data-gallery-zoom="<?= e(image_url($mediaId)) ?>" aria-label="Enlarge image <?= $i + 1 ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                        <?= image($mediaId, ['alt' => $product['name'], 'max' => 600, 'sizes' => '(max-width: 1024px) 100vw, 560px', 'loading' => $i === 0 ? 'eager' : 'lazy', 'fetchpriority' => $i === 0 ? 'high' : null]) ?>
                    </button>
                <?php endforeach ?>
                <?php if (!$images): ?><div class="gallery__placeholder" aria-hidden="true"></div><?php endif ?>
                <span class="gallery__zoom-icon" aria-hidden="true"><?= icon('search') ?></span>
            </div>
            <?php if (count($images) > 1): ?>
                <ul class="gallery__thumbs">
                    <?php foreach ($images as $i => $mediaId): ?>
                        <li><button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-gallery-thumb="<?= $i ?>" aria-label="Show image <?= $i + 1 ?>"><?= image($mediaId, ['alt' => '', 'max' => 100, 'sizes' => '90px']) ?></button></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>

        <div class="product__summary">
            <h1 class="product__title"><?= e($product['name']) ?></h1>
            <div class="product__meta">
                <?php if ((int) $product['is_premier_partner']): ?><?= image(config('catalog.premier_badge'), ['alt' => 'NiCa Premiere Partner', 'max' => 46, 'sizes' => '46px', 'class' => 'product__badge']) ?><?php endif ?>
                <span class="pill"><strong>Vendor:</strong> <a href="<?= e(link_to('/store/' . $product['vendor_slug'] . '/')) ?>"><?= e($product['vendor_name']) ?></a></span>
                <?php if ($categories): ?>
                    <span class="pill"><strong>Category:</strong>
                        <?php foreach ($categories as $i => $c): ?><?= $i ? ', ' : '' ?><a href="<?= e(link_to('/collection/' . $c['path'] . '/')) ?>"><?= e($c['name']) ?></a><?php endforeach ?>
                    </span>
                <?php endif ?>
            </div>
            <?php if ((int) $product['rating_count'] > 0): ?>
                <a class="product__rating" href="#reviews"><span class="stars" aria-hidden="true"><?= str_repeat('★', (int) round((float) $product['rating_avg'])) ?></span> <?= (int) $product['rating_count'] ?> review<?= (int) $product['rating_count'] === 1 ? '' : 's' ?></a>
            <?php endif ?>

            <p class="product__price" data-price><?= $this->insert('partials/price', ['product' => $product]) ?></p>

            <?php if ($cartNotice): ?><div class="notice" role="status"><?= e($cartNotice) ?></div><?php endif ?>

            <form class="buy-form" method="post" action="<?= e(link_to('/cart/add')) ?>" data-variant-picker>
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                <input type="hidden" name="variant_id" value="<?= $single ? (int) $single['id'] : '' ?>" data-variant-input>
                <?php foreach ($variationAttributes as $attr): ?>
                    <fieldset class="picker" data-attribute="<?= (int) $attr['id'] ?>">
                        <legend><?= e($attr['name']) ?> <span class="picker__chosen" data-chosen></span></legend>
                        <div class="picker__options picker__options--<?= e($attr['display']) ?>">
                            <?php foreach ($attr['values'] as $value): ?>
                                <label class="picker__option" title="<?= e($value['name']) ?>">
                                    <input type="radio" name="attr[<?= (int) $attr['id'] ?>]" value="<?= (int) $value['id'] ?>" data-name="<?= e($value['name']) ?>">
                                    <?php if ($attr['display'] === 'swatches' && $value['swatch']): ?>
                                        <span class="picker__swatch" data-swatch="<?= e($value['swatch']) ?>"></span><span class="visually-hidden"><?= e($value['name']) ?></span>
                                    <?php else: ?>
                                        <span class="picker__label"><?= e($value['name']) ?></span>
                                    <?php endif ?>
                                </label>
                            <?php endforeach ?>
                        </div>
                    </fieldset>
                <?php endforeach ?>
                <?php if ($isVariable): ?><button type="button" class="picker__clear" data-clear hidden>Clear</button><?php endif ?>
                <p class="buy-form__stock" data-stock aria-live="polite"><?php
                    if ($single) {
                        echo $single['in_stock'] ? ($single['stock'] !== null && $single['stock'] <= 5 ? e($single['stock'] . ' in stock') : '') : 'Out of stock';
                    }
                ?></p>
                <div class="buy-form__row">
                    <label class="visually-hidden" for="qty">Quantity</label>
                    <input id="qty" class="qty" type="number" name="quantity" value="1" min="1" max="<?= $single && $single['stock'] !== null ? max(1, (int) $single['stock']) : 99 ?>" inputmode="numeric">
                    <button class="btn btn--gold buy-form__submit" type="submit" data-add<?= ($single && !$single['in_stock']) || $isVariable ? ' disabled' : '' ?>>Add to cart</button>
                </div>
                <?php if ($isVariable): ?><p class="buy-form__hint" data-hint>Please select <?= e(strtolower(implode(' and ', array_column($variationAttributes, 'name')))) ?>.</p><?php endif ?>
            </form>
            <script type="application/json" id="variant-data" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($pickerData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>

            <?php if ($product['short_description']): ?><div class="prose product__short"><?= $product['short_description'] ?></div><?php endif ?>

            <div class="product__boxes">
                <ul class="product__perks">
                    <li><?= icon('chevron-right') ?>Premium Products</li>
                    <li><?= icon('chevron-right') ?>Free Support 24/7</li>
                    <li><?= icon('chevron-right') ?>100% Genuine Product Guarantee</li>
                </ul>
                <div class="product__share">
                    <h2>Share Product</h2>
                    <ul class="social">
                        <li><a href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="Share on Facebook"><?= icon('facebook') ?></a></li>
                        <li><a href="https://twitter.com/intent/tweet?url=<?= $shareUrl ?>&amp;text=<?= $shareTitle ?>" target="_blank" rel="noopener" aria-label="Share on X">𝕏</a></li>
                        <li><a href="https://pinterest.com/pin/create/button/?url=<?= $shareUrl ?>&amp;description=<?= $shareTitle ?>" target="_blank" rel="noopener" aria-label="Share on Pinterest">P</a></li>
                        <li><a href="https://wa.me/?text=<?= $shareTitle ?>%20<?= $shareUrl ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><?= icon('whatsapp') ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section-pad product-tabs-section">
    <div class="container">
        <div class="tabs" data-tabs>
            <div class="tabs__list" role="tablist">
                <button type="button" role="tab" id="tab-description" aria-controls="panel-description" aria-selected="true">Description</button>
                <button type="button" role="tab" id="tab-shipping" aria-controls="panel-shipping" aria-selected="false" tabindex="-1">Shipping</button>
                <?php if ($attributes): ?><button type="button" role="tab" id="tab-info" aria-controls="panel-info" aria-selected="false" tabindex="-1">Additional information</button><?php endif ?>
                <button type="button" role="tab" id="tab-reviews" aria-controls="reviews" aria-selected="false" tabindex="-1">Reviews (<?= count($reviews) ?>)</button>
            </div>
            <div class="tabs__panel prose" role="tabpanel" id="panel-description" aria-labelledby="tab-description">
                <h2 class="tabs__heading">Description</h2>
                <?= $product['description'] ?: '<p>No description yet.</p>' ?>
            </div>
            <div class="tabs__panel prose" role="tabpanel" id="panel-shipping" aria-labelledby="tab-shipping" hidden>
                <?= $shipping ?: '<p>Shipping details are provided at checkout.</p>' ?>
            </div>
            <?php if ($attributes): ?>
                <div class="tabs__panel" role="tabpanel" id="panel-info" aria-labelledby="tab-info" hidden>
                    <table class="attributes-table">
                        <?php foreach ($attributes as $attr): ?>
                            <tr><th scope="row"><?= e($attr['name']) ?></th><td><?= e(implode(', ', array_column($attr['values'], 'name'))) ?></td></tr>
                        <?php endforeach ?>
                    </table>
                </div>
            <?php endif ?>
            <div class="tabs__panel" role="tabpanel" id="reviews" aria-labelledby="tab-reviews" hidden>
                <?= $this->insert('shop/product-reviews', ['product' => $product, 'reviews' => $reviews, 'errors' => $errors, 'reviewSent' => $reviewSent]) ?>
            </div>
        </div>
    </div>
</section>

<?php if ($related): ?>
<section class="section-pad section--soft">
    <div class="container">
        <h2 class="related-title">Related Products</h2>
        <div class="product-grid"><?php foreach ($related as $p): ?><?= $this->insert('partials/product-card', ['product' => $p]) ?><?php endforeach ?></div>
    </div>
</section>
<?php endif ?>

<dialog class="lightbox" id="gallery-lightbox" aria-label="Product image">
    <button type="button" class="lightbox__close" aria-label="Close" data-dialog-close><?= icon('close') ?></button>
    <img src="" alt="<?= e($product['name']) ?>" data-lightbox-img>
</dialog>
<?php if (App\Services\Integrations::anyTracking()): ?>
<script type="application/json" id="analytics-data"><?= json_encode(['event' => 'view_item', 'currency' => 'USD', 'value' => round((int) $product['price_min_cents'] / 100, 2),
    'items' => [['item_id' => 'nica-p' . $product['id'], 'item_name' => $product['name'], 'item_brand' => $product['vendor_name'] ?? '', 'price' => round((int) $product['price_min_cents'] / 100, 2)]]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif ?>
