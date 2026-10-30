<?php /** Centered statement with three bordered value cards. */ ?>
<section class="block-mission section-pad">
    <div class="container block-mission__inner">
        <h2><?= e($b['title']) ?></h2>
        <div class="block-mission__text"><?= $b['html'] ?></div>
        <div class="block-mission__items">
            <?php foreach ($b['items'] as $item): ?>
                <div class="block-mission__item">
                    <?= image($item['image'], ['alt' => '', 'max' => 50, 'sizes' => '50px']) ?>
                    <h3><?= e($item['title']) ?></h3>
                    <p><?= e($item['text']) ?></p>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</section>
