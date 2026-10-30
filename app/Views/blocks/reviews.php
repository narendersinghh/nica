<?php
/** @var App\Core\Template $this */
$reviews = App\Services\Catalog\Storefront::topReviews((int) ($b['count'] ?? 30));
?>
<section class="section-pad block-reviews">
    <div class="container">
        <?= $this->insert('partials/section-head', ['heading' => $b['title'], 'intro' => $b['text'] ?? '']) ?>
        <?php if ($reviews): ?>
            <?= $this->insert('partials/review-cards', ['reviews' => $reviews]) ?>
        <?php else: ?>
            <p class="block-reviews__empty">Customer reviews will appear here once the shop is connected (Phase 2).</p>
        <?php endif ?>
    </div>
</section>
