<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-account']);
$nav = [
    'dashboard' => ['Dashboard', '/my-account/'],
    'orders' => ['Orders', '/my-account/orders/'],
    'points' => ['Points', '/my-account/points/'],
    'addresses' => ['Addresses', '/my-account/edit-address/'],
    'edit-account' => ['Account Details', '/my-account/edit-account/'],
    'security' => ['Security', '/my-account/security/'],
];
if (App\Services\Referrals\Referrals::enabled()) {
    $nav = array_slice($nav, 0, 3, true) + ['stylist' => ['Stylist Program', '/my-account/stylist/']] + array_slice($nav, 3, null, true);
}
if (!App\Services\Points::enabled()) {
    unset($nav['points']);
}
?>
<section class="page-banner">
    <div class="container">
        <p class="eyebrow eyebrow--light"><?= icon('user') ?><span>Your Dashboard</span></p>
        <h1>My account</h1>
    </div>
</section>
<section class="section-pad section--soft">
    <div class="container account">
        <nav class="account__nav" aria-label="Account">
            <ul>
                <?php foreach ($nav as $key => [$label, $url]): ?>
                    <li><a href="<?= e(link_to($url)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach ?>
                <?php if ($isVendor): ?><li><a href="<?= e(link_to('/dashboard/')) ?>">Vendor Dashboard</a></li><?php endif ?>
                <li><form method="post" action="<?= e(link_to('/my-account/logout')) ?>"><?= csrf_field() ?><button type="submit">Logout</button></form></li>
            </ul>
        </nav>
        <div class="account__content cart-panel">
            <?php if (!empty($notice)): ?><div class="notice notice--success" role="status"><?= icon('check') ?> <?= e($notice) ?></div><?php endif ?>
            <?= $this->section('content') ?>
        </div>
    </div>
</section>
