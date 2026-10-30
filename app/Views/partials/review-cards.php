<?php /** Testimonial cards built from approved product reviews. */
$tints = ['#FFFFFF', '#F5F5DA', '#F5E1DA', '#DCF5DA', '#D9F7F5', '#FAEDCA'];
$colored ??= false;
?>
<div class="review-cards">
    <?php foreach ($reviews as $i => $r): ?>
        <article class="review-card"<?= $colored ? ' data-tint="' . ($i % 6) . '"' : '' ?>>
            <header class="review-card__head">
                <span class="review-card__avatar" aria-hidden="true"><?= e(strtoupper(mb_substr_safe($r['author_name'], 1))) ?></span>
                <div>
                    <p class="review-card__name"><?= e($r['author_name']) ?></p>
                    <p class="stars" aria-label="Rated <?= (int) $r['rating'] ?> out of 5"><?= str_repeat('★', (int) $r['rating']) ?><span class="stars__off"><?= str_repeat('★', 5 - (int) $r['rating']) ?></span></p>
                </div>
                <?= icon('star-solid', 'review-card__mark') ?>
            </header>
            <p class="review-card__body"><?= e(App\Services\Seo::clip($r['body'], 220)) ?></p>
            <footer class="review-card__foot">
                <span>This review was given to</span>
                <a href="<?= e(link_to('/product/' . $r['product_slug'] . '/')) ?>"><?= e($r['product_name']) ?></a>
            </footer>
        </article>
    <?php endforeach ?>
</div>
