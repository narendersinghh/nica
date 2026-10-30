<?php if ($tiles): ?>
<section class="category-strip">
    <ul class="category-strip__grid">
        <?php foreach ($tiles as $c): ?>
            <li><a class="category-tile" href="<?= e($c['url']) ?>">
                <span class="category-tile__img"><?= image($c['image'], ['alt' => '', 'max' => 400, 'sizes' => '(max-width: 767px) 45vw, (max-width: 1024px) 123px, 86px']) ?></span>
                <span><span class="category-tile__name"><?= e($c['name']) ?></span><span class="category-tile__cta">Shop now <?= icon('arrow-right') ?></span></span>
            </a></li>
        <?php endforeach ?>
    </ul>
</section>
<?php endif ?>
