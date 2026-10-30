<?php /** White card on a soft background: copy + button beside an arched image. */ ?>
<section class="block-feature section-pad section--soft">
    <div class="container">
        <div class="block-feature__card">
            <div class="block-feature__copy">
                <?php if (!empty($b['eyebrow'])): ?><p class="eyebrow"><?= icon($b['icon'] ?? 'peace') ?><span><?= e($b['eyebrow']) ?></span></p><?php endif ?>
                <h2><?= e($b['title']) ?></h2>
                <div class="prose"><?= $b['html'] ?></div>
                <?php if (!empty($b['button'])): ?><a class="btn btn--dark" href="<?= e(link_to($b['button']['url'])) ?>"><?= icon($b['button']['icon'] ?? 'bag') ?><?= e($b['button']['label']) ?></a><?php endif ?>
            </div>
            <div class="block-feature__image"><?= image($b['image'], ['alt' => '', 'max' => 560, 'sizes' => '(max-width: 767px) 90vw, 470px']) ?></div>
        </div>
    </div>
</section>
