<?php /** @var App\Core\Template $this */ ?>
<div class="mobile-menu" id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu" hidden>
    <div class="mobile-menu__backdrop" data-menu-close></div>
    <div class="mobile-menu__panel">
        <div class="mobile-menu__top">
            <a href="<?= e(link_to('/')) ?>" class="mobile-menu__logo"><?= image($site['logo'], ['alt' => config('app.name'), 'max' => 100, 'sizes' => '100px']) ?></a>
            <button type="button" class="mobile-menu__close" aria-label="Close menu" data-menu-close><?= icon('close') ?></button>
        </div>
        <nav aria-label="Mobile">
            <ul>
                <?php foreach ($site['menu'] as $item): ?>
                    <li><a href="<?= e(link_to($item['url'])) ?>"<?= is_current($item['url']) ? ' class="is-active" aria-current="page"' : '' ?>><?= e($item['label']) ?></a></li>
                <?php endforeach ?>
            </ul>
        </nav>
        <a class="btn btn--gold btn--sm" href="<?= e(link_to('/find-match/')) ?>"><?= icon('magic') ?> Find My Match</a>
        <hr>
        <?= $this->insert('partials/social', ['social' => $site['social']]) ?>
        <hr>
        <a class="mobile-menu__account" href="<?= e(link_to('/my-account/')) ?>">
            <span>Login/Sign Up</span>
            <span class="mobile-menu__account-icon"><?= icon('user') ?></span>
        </a>
    </div>
</div>
