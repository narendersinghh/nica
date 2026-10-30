<?php /** For Vendors: Premiere Partners copy + pricing card, then the "why vendors choose us" grid. */ ?>
<section class="block-partner section-pad section--soft">
    <div class="container">
        <div class="block-partner__top">
            <div class="block-partner__copy">
                <h2><?= e($b['title']) ?></h2>
                <p class="block-partner__lead"><strong><?= e($b['lead']) ?></strong></p>
                <p><?= e($b['text']) ?></p>
                <p class="block-partner__list-title"><?= e($b['list_title']) ?></p>
                <ul class="checklist"><?php foreach ($b['list'] as $item): ?><li><?= icon('check') ?><?= e($item) ?></li><?php endforeach ?></ul>
                <h3 class="block-partner__why"><?= e($b['why_title']) ?></h3>
                <ul class="checklist"><?php foreach ($b['why'] as $item): ?><li><?= icon('check') ?><?= e($item) ?></li><?php endforeach ?></ul>
            </div>
            <div class="block-partner__card">
                <h3><?= e($b['card']['title']) ?></h3>
                <p class="block-partner__price"><?= e($b['card']['price']) ?></p>
                <p class="block-partner__price-label"><?= e($b['card']['price_label']) ?></p>
                <p><?= e($b['card']['text']) ?></p>
                <a class="btn btn--dark" href="<?= e(link_to($b['card']['button']['url'])) ?>"><?= e($b['card']['button']['label']) ?><?= icon('arrow-right') ?></a>
            </div>
        </div>
        <div class="block-partner__reasons">
            <h2><?= e($b['reasons_title']) ?></h2>
            <span class="block-partner__divider" aria-hidden="true"></span>
            <div class="block-partner__grid">
                <?php foreach ($b['reasons'] as $r): ?>
                    <div class="block-partner__reason">
                        <?= icon($r['icon'], 'block-partner__icon') ?>
                        <h4><?= e($r['title']) ?></h4>
                        <p><?= e($r['text']) ?></p>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
        <ul class="block-partner__pills"><?php foreach ($b['pills'] as $pill): ?><li><?= e($pill) ?></li><?php endforeach ?></ul>
    </div>
</section>
