<?php /** @var array $post */ $showExcerpt ??= false; ?>
<article class="post-card">
    <a class="post-card__image" href="<?= e(App\Models\Post::url($post)) ?>" tabindex="-1" aria-hidden="true">
        <?= image($post['featured_media_id'] ? (int) $post['featured_media_id'] : null, ['alt' => '', 'max' => 400, 'sizes' => '(max-width: 767px) 100vw, (max-width: 1024px) 50vw, 290px']) ?>
    </a>
    <div class="post-card__body">
        <h3 class="post-card__title"><a href="<?= e(App\Models\Post::url($post)) ?>"><?= e($post['title']) ?></a></h3>
        <?php if ($showExcerpt && !empty($post['excerpt'])): ?><p class="post-card__excerpt"><?= e(App\Services\Seo::clip($post['excerpt'], 140)) ?></p><?php endif ?>
        <?php if ($showExcerpt): ?><a class="post-card__more" href="<?= e(App\Models\Post::url($post)) ?>" aria-label="Read more: <?= e($post['title']) ?>">Read More »</a><?php endif ?>
        <time class="post-card__date" datetime="<?= e(date('c', strtotime($post['published_at']))) ?>"><?= e(date('F j, Y', strtotime($post['published_at']))) ?></time>
    </div>
</article>
