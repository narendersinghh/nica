<?php /** @var App\Core\Template $this */ ?>
<section class="block-team section-pad section--soft">
    <div class="container block-team__inner">
        <?= $this->insert('partials/section-head', ['eyebrow' => $b['eyebrow'] ?? '', 'eyebrowIcon' => $b['icon'] ?? 'peace', 'heading' => $b['title'], 'intro' => $b['text'] ?? '']) ?>
        <div class="block-team__grid">
            <?php foreach ($b['members'] as $m): ?>
                <figure class="block-team__member">
                    <?= image($m['image'], ['alt' => $m['name'], 'max' => 430, 'sizes' => '(max-width: 767px) 90vw, 430px']) ?>
                    <figcaption><h3><?= e($m['name']) ?></h3><p><?= e($m['role']) ?></p></figcaption>
                </figure>
            <?php endforeach ?>
        </div>
    </div>
</section>
