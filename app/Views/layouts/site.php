<?php
/** @var App\Core\Template $this */
/** @var App\Services\Seo $seo */
$site = App\Services\Settings::get('site'); // config/site.php + admin overrides
$seo ??= App\Services\Seo::make($title ?? '');
$headerOverlay ??= false;
?>
<!doctype html>
<html lang="en-US" data-base="<?= e(app()->request()->basePath()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($seo->title) ?></title>
    <?php if ($seo->description !== ''): ?><meta name="description" content="<?= e($seo->description) ?>"><?php endif ?>
    <?php if ($seo->robots): ?><meta name="robots" content="<?= e($seo->robots) ?>"><?php else: ?><meta name="robots" content="index, follow, max-image-preview:large"><?php endif ?>
    <?php if ($seo->canonical): ?><link rel="canonical" href="<?= e($seo->canonical) ?>"><?php endif ?>
    <meta property="og:site_name" content="<?= e(config('app.name')) ?>">
    <meta property="og:type" content="<?= e($seo->type) ?>">
    <meta property="og:title" content="<?= e($seo->title) ?>">
    <?php if ($seo->description !== ''): ?><meta property="og:description" content="<?= e($seo->description) ?>"><?php endif ?>
    <?php if ($seo->canonical): ?><meta property="og:url" content="<?= e($seo->canonical) ?>"><?php endif ?>
    <?php if ($seo->image): ?><meta property="og:image" content="<?= e($seo->image) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif ?>
    <link rel="icon" href="<?= e(upload_url($site['favicon'])) ?>">
    <link rel="preload" href="<?= e(asset('assets/fonts/inter-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= e(asset('assets/fonts/cormorant-garamond-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
    <?= $this->section('head') ?>
    <?php foreach ($seo->schema as $schema): ?>
    <script type="application/ld+json" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    <?php endforeach ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $this->insert('partials/icons') ?>
<a class="skip-link" href="#main">Skip to content</a>

<?= $this->insert('partials/header', ['site' => $site, 'overlay' => $headerOverlay]) ?>

<main id="main">
<?= $this->section('content') ?>
</main>

<?= $this->insert('partials/footer', ['site' => $site]) ?>
<?= $this->insert('partials/mobile-menu', ['site' => $site]) ?>
<div class="drawer" id="cart-drawer" role="dialog" aria-modal="true" aria-labelledby="cart-drawer-title" hidden>
    <div class="drawer__backdrop" data-drawer-close></div>
    <div class="drawer__panel">
        <div class="drawer__head"><h2 id="cart-drawer-title">Your cart</h2><button type="button" class="drawer__close" aria-label="Close cart" data-drawer-close><?= icon('close') ?></button></div>
        <div class="drawer__body" data-drawer-body><p class="drawer__empty">Loading…</p></div>
    </div>
</div>

<script src="<?= e(asset('assets/js/site.js')) ?>" defer></script>
<?php if (App\Services\Integrations::anyTracking()): ?>
<div class="consent" data-consent role="dialog" aria-live="polite" aria-label="Cookie preferences" hidden>
    <p>We use cookies to understand how the site is used and to show relevant offers. <a href="<?= e(link_to('/privacy-policy/')) ?>">Privacy policy</a></p>
    <div class="consent__actions"><button type="button" class="btn btn--outline" data-consent-choice="no">Decline</button><button type="button" class="btn btn--gold" data-consent-choice="yes">Accept</button></div>
</div>
<script src="<?= e(asset('assets/js/consent.js')) ?>" defer data-config="<?= e(json_encode(App\Services\Integrations::clientConfig())) ?>"></script>
<?php endif ?>
<?= $this->section('scripts') ?>
</body>
</html>
