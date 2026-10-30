<?php
/** @var App\Core\Template $this */
use App\Models\Post;
use App\Services\Catalog\Storefront;

$this->layout('layouts/site', ['headerOverlay' => true, 'bodyClass' => 'page-home']);
$hero = $data['hero'];
$categories = Storefront::featuredCategories();
$hairTypes = Storefront::hairTypes(4);
$products = Storefront::featuredProducts(4);
$reviews = Storefront::topReviews((int) ($data['testimonials']['count'] ?? 3));
$posts = Post::latest((int) ($data['journal']['count'] ?? 4));
?>

<section class="hero">
    <div class="hero__bg" aria-hidden="true"><?= image($hero['background'], ['alt' => '', 'sizes' => '100vw', 'loading' => 'eager', 'fetchpriority' => 'high', 'max' => 1536]) ?></div>
    <div class="hero__inner">
        <div class="hero__content">
            <p class="eyebrow eyebrow--light"><?= icon('crown') ?><span><?= e($hero['eyebrow']) ?></span></p>
            <h1 class="hero__title">
                <?php foreach ($hero['lines'] as $line): ?><?= e($line) ?><br><?php endforeach ?>
                <span class="hl"><?= e($hero['highlight']) ?></span>
            </h1>
            <p class="hero__text"><?= e($hero['text']) ?></p>
            <div class="hero__actions">
                <a class="btn btn--gold" href="<?= e(link_to($hero['primary']['url'])) ?>"><?= e($hero['primary']['label']) ?><?= icon('arrow-right') ?></a>
                <span class="hero__break" aria-hidden="true"></span>
                <a class="btn btn--outline-light" href="<?= e(link_to($hero['secondary']['url'])) ?>"><?= e($hero['secondary']['label']) ?></a>
                <?php if (!empty($hero['video'])): ?>
                    <button class="hero__play" type="button" data-video-open="hero-video">
                        <span class="hero__play-icon"><?= icon('play') ?></span><span>Play</span>
                    </button>
                <?php endif ?>
            </div>
        </div>
        <div class="hero__image">
            <?= image($hero['image'], ['alt' => '', 'sizes' => '(max-width: 1024px) 80vw, 560px', 'loading' => 'eager', 'fetchpriority' => 'high', 'max' => 560]) ?>
        </div>
        <div class="hero__features">
            <?php foreach ($hero['features'] as $f): ?>
                <div class="hero__feature">
                    <?= icon($f['icon'], 'hero__feature-icon') ?>
                    <div><p class="hero__feature-title"><?= e($f['title']) ?></p><p class="hero__feature-text"><?= e($f['text']) ?></p></div>
                </div>
            <?php endforeach ?>
            <a class="hero__news" href="<?= e($hero['news']['url']) ?>" target="_blank" rel="noopener">
                <span><?= e($hero['news']['label']) ?></span>
                <?= image($hero['news']['logo'], ['alt' => 'News Channel 9', 'max' => 145, 'sizes' => '145px']) ?>
            </a>
        </div>
    </div>
</section>

<?php if (!empty($hero['video'])): ?>
<dialog class="video-dialog" id="hero-video" aria-label="NiCa video">
    <button type="button" class="video-dialog__close" aria-label="Close video" data-dialog-close><?= icon('close') ?></button>
    <video controls preload="none" playsinline data-src="<?= e(upload_url($hero['video'])) ?>"></video>
</dialog>
<?php endif ?>

<div class="marquee" role="marquee" aria-label="<?= e(implode(', ', $data['marquee'])) ?>">
    <div class="marquee__track" aria-hidden="true">
        <?php for ($i = 0; $i < 3; $i++): foreach ($data['marquee'] as $item): ?>
            <span><?= e($item) ?></span><span>✦</span>
        <?php endforeach; endfor ?>
    </div>
</div>

<?= $this->insert('partials/category-tiles', ['tiles' => $categories]) ?>

<?php if ($hairTypes): ?>
<section class="section section--soft">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['hair_types']['eyebrow'], 'eyebrowIcon' => 'feather', 'heading' => $data['hair_types']['title']]) ?>
        <div class="hair-types">
            <?php foreach ($hairTypes as $t): ?>
                <a class="hair-type" href="<?= e($t['url']) ?>">
                    <?= image($t['image'], ['alt' => '', 'max' => 768, 'sizes' => '(max-width: 767px) 100vw, (max-width: 1024px) 50vw, 290px']) ?>
                    <span class="hair-type__label"><span class="hair-type__sub"><?= e($t['subtitle']) ?></span><span class="hair-type__name"><?= e($t['name']) ?></span></span>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</section>
<?php endif ?>

<?php if ($products): ?>
<section class="section">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['products']['eyebrow'], 'heading' => $data['products']['title']]) ?>
        <div class="product-grid"><?php foreach ($products as $product): ?><?= $this->insert('partials/product-card', ['product' => $product]) ?><?php endforeach ?></div>
        <div class="section__cta"><a class="btn btn--dark" href="<?= e(link_to($data['products']['button']['url'])) ?>"><?= e($data['products']['button']['label']) ?><?= icon('arrow-right') ?></a></div>
    </div>
</section>
<?php endif ?>

<section class="section section--dark match">
    <div class="container match__inner">
        <div class="match__intro">
            <p class="eyebrow eyebrow--gold"><?= icon('peace') ?><span><?= e($data['match']['eyebrow']) ?></span></p>
            <h2><?= e($data['match']['title']) ?></h2>
            <p class="match__text"><?= e($data['match']['text']) ?></p>
            <ul class="match__bullets"><?php foreach ($data['match']['bullets'] as $b): ?><li>✦ <?= e($b) ?></li><?php endforeach ?></ul>
            <a class="btn btn--gold" href="<?= e(link_to($data['match']['button']['url'])) ?>"><?= e($data['match']['button']['label']) ?><?= icon('arrow-right') ?></a>
        </div>
        <ol class="match__steps">
            <?php foreach ($data['match']['steps'] as $i => $step): ?>
                <li><span class="match__num" aria-hidden="true"><?= sprintf('%02d.', $i + 1) ?></span><div><h3><?= e($step['title']) ?></h3><p><?= e($step['text']) ?></p></div></li>
            <?php endforeach ?>
        </ol>
    </div>
</section>

<section class="section section--soft">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['before_after']['eyebrow'], 'heading' => $data['before_after']['title'], 'narrow' => true]) ?>
        <div class="carousel" data-carousel>
            <div class="carousel__track" data-carousel-track>
                <?php foreach ($data['before_after']['slides'] as $slide): ?>
                    <figure class="ba-card">
                        <?= image($slide['image'], ['alt' => $slide['caption'], 'max' => 400, 'sizes' => '(max-width: 767px) 85vw, 390px']) ?>
                        <figcaption><?= e($slide['caption']) ?></figcaption>
                    </figure>
                <?php endforeach ?>
            </div>
            <div class="carousel__nav">
                <button type="button" class="carousel__btn" aria-label="Previous" data-carousel-prev><?= icon('chevron-left') ?></button>
                <button type="button" class="carousel__btn" aria-label="Next" data-carousel-next><?= icon('chevron-right') ?></button>
            </div>
        </div>
    </div>
</section>

<?php if ($reviews): ?>
<section class="section">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['testimonials']['eyebrow'], 'eyebrowIcon' => 'star', 'heading' => $data['testimonials']['title'], 'intro' => $data['testimonials']['text']]) ?>
        <?= $this->insert('partials/review-cards', ['reviews' => $reviews, 'colored' => false]) ?>
        <div class="section__cta"><a class="btn btn--dark" href="<?= e(link_to($data['testimonials']['button']['url'])) ?>"><?= e($data['testimonials']['button']['label']) ?><?= icon('arrow-right') ?></a></div>
    </div>
</section>
<?php endif ?>

<?php if ($posts): ?>
<section class="section section--soft">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['journal']['eyebrow'], 'heading' => $data['journal']['title']]) ?>
        <div class="post-grid"><?php foreach ($posts as $post): ?><?= $this->insert('partials/post-card', ['post' => $post]) ?><?php endforeach ?></div>
        <div class="section__cta"><a class="btn btn--dark" href="<?= e(link_to($data['journal']['button']['url'])) ?>"><?= e($data['journal']['button']['label']) ?><?= icon('arrow-right') ?></a></div>
    </div>
</section>
<?php endif ?>

<section class="section">
    <div class="container container--narrow">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['why']['eyebrow'], 'heading' => $data['why']['title']]) ?>
        <div class="compare">
            <div class="compare__card compare__card--before">
                <p class="compare__title"><?= e($data['why']['before']['title']) ?></p>
                <ul><?php foreach ($data['why']['before']['items'] as $item): ?><li><?= icon('dot') ?><?= e($item) ?></li><?php endforeach ?></ul>
            </div>
            <div class="compare__card compare__card--after">
                <p class="compare__title"><?= e($data['why']['after']['title']) ?></p>
                <ul><?php foreach ($data['why']['after']['items'] as $item): ?><li><?= icon('dot') ?><?= e($item) ?></li><?php endforeach ?></ul>
            </div>
        </div>
    </div>
</section>

<section class="section section--soft">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $data['audience']['eyebrow'], 'heading' => $data['audience']['title']]) ?>
        <div class="audience">
            <?php foreach ($data['audience']['cards'] as $card): ?>
                <article class="audience__card">
                    <?= image($card['image'], ['alt' => '', 'max' => 400, 'sizes' => '(max-width: 767px) 90vw, 330px']) ?>
                    <h3><?= e($card['title']) ?></h3>
                    <p><?= e($card['text']) ?></p>
                    <a class="link-arrow" href="<?= e(link_to($card['url'])) ?>"><?= e($card['cta']) ?><?= icon('arrow-right') ?></a>
                </article>
            <?php endforeach ?>
        </div>
    </div>
</section>
