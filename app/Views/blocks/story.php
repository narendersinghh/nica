<?php /** Founder story: portrait + white card with long text and signature. */ ?>
<section class="block-story section-pad">
    <div class="container block-story__inner">
        <div class="block-story__image"><?= image($b['image'], ['alt' => $b['signature'] ?? '', 'max' => 649, 'sizes' => '(max-width: 767px) 100vw, 540px'] + (($blockIndex ?? 9) <= 1 ? ['loading' => 'eager', 'fetchpriority' => 'high'] : [])) ?></div>
        <div class="block-story__card">
            <h2><?= e($b['title']) ?></h2>
            <div class="prose"><?= $b['html'] /* trusted content from content file */ ?></div>
            <?php if (!empty($b['role'])): ?><p class="block-story__role"><?= e($b['role']) ?></p><?php endif ?>
            <?php if (!empty($b['motto'])): ?><p class="block-story__motto"><?= e($b['motto']) ?></p><?php endif ?>
            <?php if (!empty($b['signature'])): ?><p class="block-story__signature"><?= e($b['signature']) ?></p><?php endif ?>
        </div>
    </div>
</section>
