<?php
/** @var App\Core\Template $this */
/** @var array|null $vendor (vendor area) */
$area ??= 'vendor';
$site = config('site');
if ($area === 'admin') {
    $can = [App\Http\Middleware\AdminAccess::class, 'can'];
    $nav = array_filter([
        'overview' => ['Dashboard', '/admin/', 'home', 'dashboard'],
        'orders' => ['Orders', '/admin/orders/', 'receipt', 'orders'],
        'products' => ['Products', '/admin/products/', 'box', 'products'],
        'vendors' => ['Vendors', '/admin/vendors/', 'store', 'vendors.view'],
        'stylists' => ['Stylists', '/admin/stylists/', 'users', 'stylists'],
        'payouts' => ['Payouts', '/admin/payouts/', 'wallet', 'finance'],
        'customers' => ['Customers', '/admin/customers/', 'users', 'customers'],
        'reviews' => ['Reviews', '/admin/reviews/', 'star', 'reviews'],
        'coupons' => ['Coupons', '/admin/coupons/', 'tag', 'marketing'],
        'redirects' => ['Redirects & 404s', '/admin/redirects/', 'external', 'seo'],
        'settings' => ['Settings', '/admin/settings/', 'gear', 'settings'],
        'audit' => ['Audit log', '/admin/audit/', 'shield', 'audit'],
    ], static fn ($item) => $can($item[3]));
    $me = app()->auth()->user();
    $headName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['display_name'] ?? $me['email']);
} else {
    $nav = [
        'overview' => ['Overview', '/dashboard/', 'home'],
        'products' => ['Products', '/dashboard/products/', 'box'],
        'orders' => ['Orders', '/dashboard/orders/', 'receipt'],
        'withdraw' => ['Earnings', '/dashboard/withdraw/', 'wallet'],
        'coupons' => ['Coupons', '/dashboard/coupons/', 'tag'],
        'reviews' => ['Reviews', '/dashboard/reviews/', 'star'],
        'settings' => ['Store Settings', '/dashboard/settings/store/', 'store'],
        'payment' => ['Payout Settings', '/dashboard/settings/payment/', 'bank'],
    ];
    $headName = $vendor['store_name'];
}
$impersonating = app()->auth()->impersonatorId() !== null;
$badges ??= [];
$flash = app()->session()->getFlash('panel_notice');
$flashError = app()->session()->getFlash('panel_error');
?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? 'Dashboard') . ' · ' . ($area === 'admin' ? 'Admin' : $headName)) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= e(upload_url($site['favicon'])) ?>">
    <link rel="preload" href="<?= e(asset('assets/fonts/inter-normal-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/panel.css')) ?>">
    <?= $this->section('head') ?>
</head>
<body class="panel-body">
<?= $this->insert('partials/panel-icons') ?>
<a class="skip-link" href="#panel-main">Skip to content</a>
<div class="panel">
    <aside class="panel__side" id="panel-side" data-panel-side>
        <div class="panel__brand">
            <a href="<?= e(link_to('/')) ?>"><img src="<?= e(upload_url($site['logo_light'] ?? $site['logo'])) ?>" alt="<?= e(config('app.name')) ?>" width="150" height="44"></a>
            <button type="button" class="panel__close" data-panel-toggle aria-label="Close menu"><?= picon('close') ?></button>
        </div>
        <?php if ($area === 'admin'): ?>
        <div class="panel__store">
            <span class="panel__avatar"><?= e(strtoupper(mb_substr_safe($headName, 1))) ?></span>
            <span class="panel__store-name"><strong><?= e($headName) ?></strong><span class="status-dot status-dot--approved"><?= app()->auth()->hasRole('admin') ? 'Administrator' : 'Staff' ?></span></span>
        </div>
        <?php else: ?>
        <div class="panel__store">
            <span class="panel__avatar"><?php if ($vendor['logo_media_id']): ?><?= image((int) $vendor['logo_media_id'], ['size' => 'thumbnail', 'width' => 40, 'height' => 40, 'alt' => '']) ?><?php else: ?><?= e(mb_substr_safe($vendor['store_name'], 1)) ?><?php endif ?></span>
            <span class="panel__store-name"><strong><?= e($vendor['store_name']) ?></strong><span class="status-dot status-dot--<?= e($vendor['status']) ?>"><?= e(ucfirst($vendor['status'])) ?></span></span>
        </div>
        <?php endif ?>
        <nav class="panel__nav" aria-label="Dashboard">
            <ul>
                <?php foreach ($nav as $key => [$label, $url, $ico]): ?>
                    <li><a href="<?= e(link_to($url)) ?>"<?= ($tab ?? '') === $key ? ' aria-current="page"' : '' ?>><?= picon($ico) ?><span><?= e($label) ?></span><?php if (!empty($badges[$key])): ?><span class="panel__badge"><?= (int) $badges[$key] ?></span><?php endif ?></a></li>
                <?php endforeach ?>
            </ul>
        </nav>
        <div class="panel__foot">
            <?php if ($area === 'admin'): ?>
            <a href="<?= e(link_to('/')) ?>" target="_blank" rel="noopener"><?= picon('external') ?><span>View site</span></a>
            <?php else: ?>
            <a href="<?= e(link_to('/store/' . $vendor['slug'] . '/')) ?>" target="_blank" rel="noopener"><?= picon('external') ?><span>View store</span></a>
            <?php endif ?>
            <a href="<?= e(link_to('/my-account/')) ?>"><?= picon('home') ?><span>My account</span></a>
            <form method="post" action="<?= e(link_to('/my-account/logout')) ?>"><?= csrf_field() ?><button type="submit"><?= picon('logout') ?><span>Log out</span></button></form>
        </div>
    </aside>
    <div class="panel__scrim" data-panel-toggle hidden></div>
    <div class="panel__main">
        <header class="panel__top">
            <button type="button" class="panel__burger" data-panel-toggle aria-controls="panel-side" aria-expanded="false" aria-label="Open menu"><?= picon('menu') ?></button>
            <div class="panel__heading">
                <?php if (!empty($back)): ?><a class="panel__back" href="<?= e(link_to($back[1])) ?>"><?= picon('arrow-left') ?><?= e($back[0]) ?></a><?php endif ?>
                <h1><?= e($title ?? 'Dashboard') ?></h1>
            </div>
            <div class="panel__actions"><?= $this->section('actions') ?></div>
        </header>
        <main id="panel-main" class="panel__content">
            <?php if ($impersonating): ?>
                <form class="alert alert--info" method="post" action="<?= e(link_to('/admin/impersonate/stop')) ?>"><?= csrf_field() ?><?= picon('shield') ?><div><strong>You are signed in as <?= e($headName) ?>.</strong> Everything you do is recorded. <button class="link-btn" type="submit">Return to admin</button></div></form>
            <?php endif ?>
            <?php if ($area === 'admin'): ?>
            <?php elseif ($vendor['status'] === 'pending'): ?>
                <div class="alert alert--warn" role="status"><?= picon('alert') ?><div><strong>Your store is awaiting approval.</strong> You can set up your store and add products now; they go live as soon as our team approves your store.</div></div>
            <?php elseif ((int) $vendor['vacation_mode']): ?>
                <div class="alert alert--info" role="status"><?= picon('alert') ?><div><strong>Vacation mode is on.</strong> Your products are hidden from the shop. <a href="<?= e(link_to('/dashboard/settings/store/#vacation')) ?>">Turn it off</a></div></div>
            <?php endif ?>
            <?php if ($flash): ?><div class="alert alert--success" role="status"><?= picon('check') ?><div><?= e($flash) ?></div></div><?php endif ?>
            <?php if ($flashError): ?><div class="alert alert--error" role="alert"><?= picon('alert') ?><div><?= e($flashError) ?></div></div><?php endif ?>
            <?= $this->section('content') ?>
        </main>
    </div>
</div>
<script src="<?= e(asset('assets/js/panel.js')) ?>" defer></script>
<?= $this->section('scripts') ?>
</body>
</html>
