<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-blog']);
['items' => $posts, 'page' => $current, 'pages' => $pages] = $result;
$pageUrl = static fn (int $n) => link_to($n === 1 ? $baseUrl : $baseUrl . 'page/' . $n . '/');
?>
<section class="page-banner">
    <div class="container">
        <p class="eyebrow eyebrow--light"><?= icon('search') ?><span>Blog - The NiCa Collection</span></p>
        <h1><?= e($title) ?></h1>
        <?php if ($intro !== ''): ?><p class="page-banner__text"><?= e($intro) ?></p><?php endif ?>
    </div>
</section>

<section class="section-pad section--soft">
    <div class="container">
        <div class="post-grid post-grid--3">
            <?php foreach ($posts as $post): ?><?= $this->insert('partials/post-card', ['post' => $post, 'showExcerpt' => true]) ?><?php endforeach ?>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Blog pages">
                <?php if ($current > 1): ?><a href="<?= e($pageUrl($current - 1)) ?>" rel="prev">« Previous</a><?php endif ?>
                <?php for ($n = 1; $n <= $pages; $n++): ?>
                    <?php if ($n === $current): ?><span aria-current="page"><?= $n ?></span><?php else: ?><a href="<?= e($pageUrl($n)) ?>"><?= $n ?></a><?php endif ?>
                <?php endfor ?>
                <?php if ($current < $pages): ?><a href="<?= e($pageUrl($current + 1)) ?>" rel="next">Next »</a><?php endif ?>
            </nav>
        <?php endif ?>
    </div>
</section>
