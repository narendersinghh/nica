<?php /** Dark page header: eyebrow, H1, intro. */ ?>
<section class="page-banner">
    <div class="container">
        <?php if (!empty($b['eyebrow'])): ?><p class="eyebrow eyebrow--light"><?= icon($b['icon'] ?? 'peace') ?><span><?= e($b['eyebrow']) ?></span></p><?php endif ?>
        <h1><?= e($b['title'] ?? $page['title']) ?></h1>
        <?php if (!empty($b['text'])): ?><p class="page-banner__text"><?= e($b['text']) ?></p><?php endif ?>
        <?php if (!empty($b['button'])): ?><a class="btn btn--gold" href="<?= e(link_to($b['button']['url'])) ?>"><?= e($b['button']['label']) ?><?= icon('arrow-right') ?></a><?php endif ?>
    </div>
</section>
