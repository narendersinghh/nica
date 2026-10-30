<?php /** Copy with bullet list + button, image on the other side. */ ?>
<section class="block-split section-pad<?= ($b['background'] ?? 'soft') === 'soft' ? ' section--soft' : '' ?>">
    <div class="container block-split__inner">
        <div class="block-split__copy">
            <?php if (!empty($b['eyebrow'])): ?><p class="eyebrow"><?= icon($b['icon'] ?? 'peace') ?><span><?= e($b['eyebrow']) ?></span></p><?php endif ?>
            <h2><?= e($b['title']) ?></h2>
            <?php if (!empty($b['html'])): ?><div class="prose block-split__text"><?= $b['html'] ?></div><?php endif ?>
            <?php if (!empty($b['bullets'])): ?>
                <ul class="checklist checklist--chevron"><?php foreach ($b['bullets'] as $bullet): ?><li><?= icon('chevron-right') ?><?= e($bullet) ?></li><?php endforeach ?></ul>
            <?php endif ?>
            <?php if (!empty($b['button'])): ?><a class="btn btn--dark" href="<?= e(link_to($b['button']['url'])) ?>"><?= e($b['button']['label']) ?><?= icon('arrow-right') ?></a><?php endif ?>
        </div>
        <div class="block-split__image"><?= image($b['image'], ['alt' => '', 'max' => 600, 'sizes' => '(max-width: 767px) 90vw, 580px']) ?></div>
    </div>
</section>
