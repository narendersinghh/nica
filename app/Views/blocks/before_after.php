<?php /** @var App\Core\Template $this */ ?>
<section class="section section--soft">
    <div class="container">
        <?= $this->insert('partials/section-head', ['eyebrow' => $b['eyebrow'] ?? '', 'heading' => $b['title']]) ?>
        <div class="carousel" data-carousel>
            <div class="carousel__track" data-carousel-track>
                <?php foreach ($b['slides'] as $slide): ?>
                    <figure class="ba-card">
                        <?= image($slide['image'], ['alt' => $slide['caption'], 'max' => 400, 'sizes' => '(max-width: 767px) 85vw, 390px']) ?>
                        <figcaption><?= e($slide['caption']) ?></figcaption>
                    </figure>
                <?php endforeach ?>
            </div>
            <div class="carousel__nav">
                <button type="button" class="carousel__btn" aria-label="Previous" data-carousel-prev><?= icon('chevron-left') ?></button>
                <button type="button" class="carousel__btn" aria-label="Next" data-carousel-next><?= icon('chevron-right') ?></button>
            </div>
        </div>
    </div>
</section>
