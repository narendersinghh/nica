<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-post']);
$published = strtotime($post['published_at']);
$share = rawurlencode($shareUrl ?? '');
$shareTitle = rawurlencode($post['title']);
?>
<section class="page-banner page-banner--post">
    <div class="container">
        <a class="eyebrow eyebrow--light" href="<?= e(link_to('/blog/')) ?>"><?= icon('chevron-left') ?><span>Back to Blogs</span></a>
        <h1><?= e($post['title']) ?></h1>
        <ul class="post-meta">
            <?php if ($post['author_name'] !== ''): ?><li><?= icon('user') ?><?= e($post['author_name']) ?></li><?php endif ?>
            <li><?= icon('clock') ?><time datetime="<?= e(date('c', $published)) ?>"><?= e(date('F j, Y', $published)) ?></time></li>
            <li><?= icon('book') ?><?= (int) $post['reading_minutes'] ?> min read</li>
        </ul>
    </div>
</section>

<section class="section-pad section--soft">
    <div class="container post-layout">
        <article class="post-layout__main">
            <?php if ($post['featured_media_id']): ?>
                <div class="post-layout__image"><?= image((int) $post['featured_media_id'], ['alt' => $post['title'], 'max' => 768, 'sizes' => '(max-width: 1024px) 100vw, 720px', 'loading' => 'eager', 'fetchpriority' => 'high']) ?></div>
            <?php endif ?>
            <div class="prose prose--post"><?= $content /* sanitized at import / on save */ ?></div>
            <div class="share">
                <h2>Share this Article</h2>
                <ul>
                    <li><a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $share ?>" target="_blank" rel="noopener" aria-label="Share on LinkedIn">in</a></li>
                    <li><a href="https://wa.me/?text=<?= $shareTitle ?>%20<?= $share ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><?= icon('whatsapp') ?></a></li>
                    <li><a href="mailto:?subject=<?= $shareTitle ?>&amp;body=<?= $share ?>" aria-label="Share by email"><?= icon('envelope') ?></a></li>
                    <li><a href="<?= e(link_to('/' . $post['slug'] . '/')) ?>#print" data-print aria-label="Print">⎙</a></li>
                </ul>
            </div>
        </article>
        <aside class="post-layout__aside">
            <div class="aside-card">
                <h3>Latest Posts</h3>
                <ul class="mini-posts">
                    <?php foreach ($latest as $p): ?>
                        <li><a href="<?= e(App\Models\Post::url($p)) ?>">
                            <?= image($p['featured_media_id'] ? (int) $p['featured_media_id'] : null, ['alt' => '', 'max' => 150, 'sizes' => '72px']) ?>
                            <span><?= e($p['title']) ?></span>
                        </a></li>
                    <?php endforeach ?>
                </ul>
            </div>
            <div class="aside-card aside-card--dark">
                <span class="aside-card__icon"><?= icon('bag') ?></span>
                <h4>Find the perfect hair for your next look?</h4>
                <p>Explore a curated selection of premium extensions and wigs from trusted vendors on NiCa Collection.</p>
                <a class="aside-card__link" href="<?= e(link_to('/shop/')) ?>">Start exploring now <?= icon('arrow-right') ?></a>
            </div>
        </aside>
    </div>
</section>

<?php if ($related): ?>
<section class="section-pad">
    <div class="container">
        <h3>Read Related Blog</h3>
        <div class="post-grid post-grid--3"><?php foreach ($related as $p): ?><?= $this->insert('partials/post-card', ['post' => $p, 'showExcerpt' => true]) ?><?php endforeach ?></div>
    </div>
</section>
<?php endif ?>
