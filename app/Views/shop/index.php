<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-shop']);
['items' => $products, 'page' => $current, 'pages' => $pages, 'total' => $total] = $result;
$activeCategory = $category['id'] ?? null;
$query = $sort !== 'latest' && $sort !== 'relevance' ? ['sort' => $sort] : [];
if (!empty($search)) {
    $query = ['s' => $search] + $query;
}
?>
<section class="page-banner">
    <div class="container">
        <?php if (!empty($eyebrowUrl)): ?>
            <a class="eyebrow eyebrow--light" href="<?= e(link_to($eyebrowUrl)) ?>"><?= icon('chevron-left') ?><span><?= e($eyebrow) ?></span></a>
        <?php else: ?>
            <p class="eyebrow eyebrow--light"><?= icon('search') ?><span><?= e($eyebrow) ?></span></p>
        <?php endif ?>
        <h1><?= e($title) ?></h1>
        <?php if ($intro !== ''): ?><p class="page-banner__text"><?= e($intro) ?></p><?php endif ?>
        <form class="shop-search" action="<?= e(link_to('/shop/')) ?>" method="get" role="search">
            <label class="visually-hidden" for="shop-search">Search products</label>
            <input id="shop-search" type="search" name="s" value="<?= e($search ?? '') ?>" placeholder="Type to start searching..." maxlength="100" autocomplete="off">
            <button type="submit" aria-label="Search"><?= icon('search') ?></button>
        </form>
    </div>
</section>

<?= $this->insert('partials/category-tiles', ['tiles' => $tiles]) ?>

<section class="section-pad section--soft shop">
    <div class="container">
        <div class="shop__bar">
            <h2 class="shop__heading">Filter the products or take a quiz</h2>
            <a href="<?= e(link_to('/find-match/')) ?>" class="shop__quiz" aria-label="Take the Find My Match quiz"><?= icon('arrow-right') ?></a>
            <form class="shop__sort" method="get" action="">
                <?php if (!empty($search)): ?><input type="hidden" name="s" value="<?= e($search) ?>"><?php endif ?>
                <label class="visually-hidden" for="shop-sort">Sort products</label>
                <select id="shop-sort" name="sort" data-autosubmit>
                    <?php if (!empty($search)): ?><option value="relevance"<?= $sort === 'relevance' ? ' selected' : '' ?>>Relevance</option><?php endif ?>
                    <?php foreach ($sorts as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $sort === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <noscript><button type="submit" class="btn btn--dark btn--sm">Sort</button></noscript>
            </form>
        </div>
        <ul class="filter-pills" aria-label="Categories">
            <li><a href="<?= e(link_to('/shop/')) ?>"<?= $activeCategory === null && empty($search) ? ' class="is-active" aria-current="page"' : '' ?>>All</a></li>
            <?php foreach ($pills as $pill): ?>
                <li><a href="<?= e(link_to('/collection/' . $pill['path'] . '/')) ?>"<?= (int) $pill['id'] === (int) $activeCategory ? ' class="is-active" aria-current="page"' : '' ?>><?= e($pill['name']) ?></a></li>
            <?php endforeach ?>
        </ul>

        <?php if ($products): ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?><?= $this->insert('partials/product-card', ['product' => $product]) ?><?php endforeach ?>
            </div>
            <?= $this->insert('partials/pagination', ['base' => $base, 'current' => $current, 'pages' => $pages, 'query' => $query]) ?>
        <?php else: ?>
            <div class="empty-state">
                <p>It seems we can’t find what you’re looking for.</p>
                <a class="btn btn--dark" href="<?= e(link_to('/find-match/')) ?>">Take the Match Quiz <?= icon('arrow-right') ?></a>
            </div>
        <?php endif ?>
    </div>
</section>
