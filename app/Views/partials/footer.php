<?php /** @var App\Core\Template $this */ ?>
<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__brand">
            <a href="<?= e(link_to('/')) ?>" class="site-footer__logo" aria-label="<?= e(config('app.name')) ?> home">
                <?= image($site['logo'], ['alt' => config('app.name'), 'max' => 100, 'sizes' => '100px']) ?>
            </a>
            <p><?= e($site['footer_blurb']) ?></p>
            <?= $this->insert('partials/social', ['social' => $site['social']]) ?>
        </div>
        <div class="site-footer__col">
            <h2>Navigation</h2>
            <ul><?php foreach ($site['footer_navigation'] as $l): ?><li><a href="<?= e(link_to($l['url'])) ?>"><?= e($l['label']) ?></a></li><?php endforeach ?></ul>
        </div>
        <div class="site-footer__col">
            <h2>Quick Links</h2>
            <ul><?php foreach ($site['footer_quick_links'] as $l): ?><li><a href="<?= e(link_to($l['url'])) ?>"><?= e($l['label']) ?></a></li><?php endforeach ?></ul>
        </div>
        <div class="site-footer__col site-footer__contact">
            <h2>Contact</h2>
            <ul>
                <li><?= icon('map-marker') ?><span><?= e($site['contact']['address']) ?></span></li>
                <li><?= icon('phone') ?><a href="<?= e($site['contact']['phone_href']) ?>"><?= e($site['contact']['phone']) ?></a></li>
                <li><?= icon('envelope') ?><a href="mailto:<?= e($site['contact']['email']) ?>"><?= e($site['contact']['email']) ?></a></li>
            </ul>
            <a class="site-footer__whatsapp" href="<?= e($site['contact']['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><?= icon('whatsapp') ?></a>
        </div>
        <div class="site-footer__bottom">
            <p>© <?= date('Y') ?>. The NiCa Collection LLC. All rights reserved.</p>
            <ul class="site-footer__legal">
                <?php foreach ($site['legal_links'] as $l): ?><li><a href="<?= e(link_to($l['url'])) ?>"><?= e($l['label']) ?></a></li><?php endforeach ?>
                <?php if (App\Services\Integrations::anyTracking()): ?><li><button type="button" class="link-button" data-consent-open>Cookie settings</button></li><?php endif ?>
            </ul>
            <p class="site-footer__credit">Crafted by: <a href="<?= e($site['credit']['url']) ?>" target="_blank" rel="noopener"><?= e($site['credit']['label']) ?></a></p>
        </div>
    </div>
</footer>
