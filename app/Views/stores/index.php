<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-stores']); ?>
<section class="page-banner">
    <div class="container">
        <a class="eyebrow eyebrow--light" href="<?= e(link_to('/shop/')) ?>"><?= icon('chevron-left') ?><span>Explore Marketplace</span></a>
        <h1>Store List</h1>
    </div>
</section>
<section class="section-pad section--soft">
    <div class="container">
        <form class="store-toolbar" method="get" action="<?= e(link_to('/store-listing/')) ?>">
            <p>Total stores showing: <strong><?= count($vendors) ?></strong></p>
            <div class="store-toolbar__controls">
                <label class="visually-hidden" for="store-q">Search stores</label>
                <input id="store-q" type="search" name="q" value="<?= e($search) ?>" placeholder="Search stores..." maxlength="60">
                <label for="store-sort">Sort by:</label>
                <select id="store-sort" name="sort" data-autosubmit>
                    <?php foreach (['recent' => 'Most Recent', 'name' => 'Alphabetical', 'rating' => 'Top Rated', 'featured' => 'Featured'] as $k => $label): ?>
                        <option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <button class="btn btn--gold btn--sm" type="submit"><?= icon('search') ?> Filter</button>
            </div>
        </form>
        <?php if ($vendors): ?>
            <ul class="store-grid">
                <?php foreach ($vendors as $v): $url = link_to('/store/' . $v['slug'] . '/'); ?>
                    <li class="store-card">
                        <a class="store-card__banner" href="<?= e($url) ?>">
                            <?= image($v['banner_media_id'] ?: config('catalog.default_store_banner'), ['alt' => '', 'max' => 400, 'sizes' => '(max-width: 767px) 100vw, 380px']) ?>
                            <span class="store-card__info">
                                <span class="store-card__name"><?= e($v['store_name']) ?></span>
                                <?php if ((int) $v['rating_count'] > 0): ?><span class="stars" aria-label="Rated <?= e($v['rating_avg']) ?> out of 5"><?= str_repeat('★', (int) round((float) $v['rating_avg'])) ?></span><?php endif ?>
                            </span>
                            <span class="store-card__logo"><?= image($v['logo_media_id'] ?: config('catalog.default_store_logo'), ['alt' => '', 'max' => 80, 'sizes' => '64px']) ?></span>
                        </a>
                        <div class="store-card__foot">
                            <a class="store-card__go" href="<?= e($url) ?>" aria-label="Visit <?= e($v['store_name']) ?>"><?= icon('chevron-right') ?></a>
                            <?php if ((int) $v['is_premier_partner']): ?><span class="store-card__premier">Premiere Partner #<?= (int) $v['premier_number'] ?></span><?php endif ?>
                        </div>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php else: ?>
            <p class="empty-state">No stores found.</p>
        <?php endif ?>
    </div>
</section>
