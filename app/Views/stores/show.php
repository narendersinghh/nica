<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-store']);
$base = '/store/' . $vendor['slug'] . '/';
?>
<section class="store-hero">
    <div class="container store-hero__inner">
        <span class="store-hero__logo"><?= image($vendor['logo_media_id'] ?: config('catalog.default_store_logo'), ['alt' => '', 'max' => 140, 'sizes' => '120px', 'loading' => 'eager']) ?></span>
        <div>
            <h1><?= e($vendor['store_name']) ?></h1>
            <?php if ((int) $vendor['rating_count'] > 0): ?>
                <p class="store-hero__rating"><span class="stars" aria-hidden="true">★</span> <?= e(number_format((float) $vendor['rating_avg'], 2)) ?> rating from <?= (int) $vendor['rating_count'] ?> review<?= (int) $vendor['rating_count'] === 1 ? '' : 's' ?></p>
            <?php endif ?>
            <?php if ((int) $vendor['is_premier_partner']): ?><p class="store-hero__premier"><?= image(config('catalog.premier_badge'), ['alt' => '', 'max' => 30, 'sizes' => '24px']) ?> NiCa Premiere Partner #<?= (int) $vendor['premier_number'] ?></p><?php endif ?>
            <?php if ($vendor['tagline']): ?><p class="store-hero__tagline"><?= e($vendor['tagline']) ?></p><?php endif ?>
        </div>
    </div>
</section>
<section class="section-pad section--soft">
    <div class="container">
        <nav class="store-tabs" aria-label="Store sections">
            <a href="<?= e(link_to($base)) ?>"<?= $tab === 'products' ? ' aria-current="page"' : '' ?>>Products</a>
            <a href="<?= e(link_to($base . 'reviews/')) ?>"<?= $tab === 'reviews' ? ' aria-current="page"' : '' ?>>Reviews</a>
        </nav>
        <?php if ($tab === 'products'): ?>
            <?php if ($result['items']): ?>
                <div class="product-grid"><?php foreach ($result['items'] as $p): ?><?= $this->insert('partials/product-card', ['product' => $p]) ?><?php endforeach ?></div>
                <?= $this->insert('partials/pagination', ['base' => $base, 'current' => $result['page'], 'pages' => $result['pages']]) ?>
            <?php else: ?>
                <p class="empty-state">This store has no products yet.</p>
            <?php endif ?>
        <?php else: ?>
            <?php if ($reviews): ?>
                <?= $this->insert('partials/review-cards', ['reviews' => $reviews, 'colored' => true]) ?>
            <?php else: ?>
                <p class="empty-state">No reviews yet.</p>
            <?php endif ?>
        <?php endif ?>
    </div>
</section>
