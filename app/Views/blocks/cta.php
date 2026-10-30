<section class="block-cta section-pad<?= !empty($b['soft']) ? ' section--soft' : '' ?>">
    <div class="container block-cta__inner">
        <<?= ($b['tag'] ?? 'h2') === 'h3' ? 'h3' : 'h2' ?>><?= e($b['title']) ?></<?= ($b['tag'] ?? 'h2') === 'h3' ? 'h3' : 'h2' ?>>
        <?php if (!empty($b['text'])): ?><p><?= e($b['text']) ?></p><?php endif ?>
        <?php if (!empty($b['button'])): ?><a class="btn btn--dark" href="<?= e(link_to($b['button']['url'])) ?>"><?= e($b['button']['label']) ?><?= icon($b['button']['icon'] ?? 'arrow-right') ?></a><?php endif ?>
    </div>
</section>
