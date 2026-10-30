<?php /** Rich-text page (legal pages etc.): banner + content. @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-' . e($page['slug'])]); ?>
<section class="page-banner">
    <div class="container">
        <a class="eyebrow eyebrow--light" href="<?= e(link_to('/shop/')) ?>"><?= icon('chevron-left') ?><span>Explore Marketplace</span></a>
        <h1><?= e($page['title']) ?></h1>
    </div>
</section>
<section class="section-pad">
    <div class="container">
        <article class="prose prose--page"><?= $page['content'] /* sanitized at import / on save */ ?></article>
    </div>
</section>
