<?php /** Narrow centered card for password / 2FA screens. @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-account']); ?>
<section class="page-banner"><div class="container"><p class="eyebrow eyebrow--light"><?= icon('user') ?><span>Your Dashboard</span></p><h1><?= e($heading) ?></h1></div></section>
<section class="section-pad section--soft"><div class="container"><div class="cart-panel auth-narrow"><?= $this->section('content') ?></div></div></section>
