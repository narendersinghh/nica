<?php /** Grid of white cards with an emoji, title and text (For Stylists / For Customers). */ ?>
<section class="block-cards section-pad section--soft">
    <div class="container">
        <div class="block-cards__grid block-cards__grid--<?= (int) ($b['columns'] ?? 3) ?>">
            <?php foreach ($b['cards'] as $card): ?>
                <div class="block-cards__card">
                    <span class="block-cards__emoji" aria-hidden="true"><?= e($card['emoji']) ?></span>
                    <h4><?= e($card['title']) ?></h4>
                    <p><?= e($card['text']) ?></p>
                </div>
            <?php endforeach ?>
        </div>
        <?php if (!empty($b['title']) || !empty($b['button'])): ?>
            <div class="block-cards__footer">
                <?php if (!empty($b['title'])): ?><h3><?= e($b['title']) ?></h3><?php endif ?>
                <?php if (!empty($b['button'])): ?><a class="btn btn--dark" href="<?= e(link_to($b['button']['url'])) ?>"><?= icon($b['button']['icon'] ?? 'arrow-right') ?><?= e($b['button']['label']) ?></a><?php endif ?>
            </div>
        <?php endif ?>
    </div>
</section>
