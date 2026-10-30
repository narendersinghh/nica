<section class="block-social section-pad">
    <div class="container block-social__inner">
        <?php if (!empty($b['image'])): ?><?= image($b['image'], ['alt' => '', 'max' => 46, 'sizes' => '46px']) ?><?php endif ?>
        <h3><?= e($b['title']) ?></h3>
        <a href="<?= e($b['url']) ?>" target="_blank" rel="noopener"><?= e($b['handle']) ?></a>
    </div>
</section>
