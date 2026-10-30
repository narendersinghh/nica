<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\VendorProducts;
$this->layout('layouts/panel');
$labels = VendorProducts::STATUS_LABELS;
$filterLink = static fn (?string $s) => link_to('/dashboard/products/') . ($s ? '?status=' . $s : '');
?>
<?php $this->start('actions') ?>
<a class="btn" href="<?= e(link_to('/dashboard/products/new/')) ?>"><?= picon('plus') ?>Add product</a>
<?php $this->stop() ?>

<div class="toolbar">
    <nav class="chips" aria-label="Filter by status">
        <a href="<?= e($filterLink(null)) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>>All<span><?= array_sum($counts) - $counts['archived'] ?></span></a>
        <?php foreach ($labels as $key => $label): if (!$counts[$key] && $status !== $key) continue; ?>
            <a href="<?= e($filterLink($key)) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?><span><?= (int) $counts[$key] ?></span></a>
        <?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/dashboard/products/')) ?>" role="search">
        <?= picon('search') ?>
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
        <label class="sr-only" for="product-search">Search products</label>
        <input id="product-search" type="search" name="s" value="<?= e($search) ?>" placeholder="Search by name or SKU">
    </form>
</div>

<section class="card card--flush">
    <?php if (!$list['rows']): ?>
        <div class="empty"><?= picon('box') ?>
            <?php if ($search !== '' || $status): ?>No products match. <a href="<?= e(link_to('/dashboard/products/')) ?>">Clear filters</a>
            <?php else: ?><p>You have not added any products yet.</p><a class="btn" href="<?= e(link_to('/dashboard/products/new/')) ?>"><?= picon('plus') ?>Add your first product</a><?php endif ?>
        </div>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Product</th><th>Status</th><th>Price</th><th>Stock</th><th class="right">Sold</th><th class="right">Updated</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $p): $edit = link_to('/dashboard/products/' . $p['id'] . '/'); ?>
            <tr>
                <td class="prod-col">
                    <div class="prod-cell">
                        <a class="thumb" href="<?= e($edit) ?>" tabindex="-1"><?php if ($p['featured_media_id']): ?><?= image((int) $p['featured_media_id'], ['size' => 'thumbnail', 'width' => 44, 'height' => 44, 'alt' => '']) ?><?php endif ?></a>
                        <div>
                            <a href="<?= e($edit) ?>"><strong><?= e($p['name']) ?></strong></a>
                            <span class="muted small"><?= e($p['category'] ?? 'No category') ?><?= $p['type'] === 'variable' ? ' · ' . (int) $p['variant_count'] . ' variations' : '' ?></span>
                            <div class="row-actions">
                                <a href="<?= e($edit) ?>">Edit</a>
                                <?php if ($p['status'] === 'published'): ?><a href="<?= e(link_to('/product/' . $p['slug'] . '/')) ?>" target="_blank" rel="noopener">View</a><?php endif ?>
                                <form class="inline-form" method="post" action="<?= e(link_to('/dashboard/products/' . $p['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="link-btn" type="submit">Duplicate</button></form>
                            </div>
                        </div>
                    </div>
                </td>
                <td data-label="Status"><?= $this->insert('vendor/_status', ['status' => $p['status'], 'label' => $labels[$p['status']] ?? $p['status']]) ?><?php if ($p['status'] === 'rejected' && $p['review_note']): ?><div class="muted small"><?= e($p['review_note']) ?></div><?php endif ?></td>
                <td data-label="Price" class="num nowrap">
                    <?php if ((int) $p['price_max_cents'] === 0): ?><span class="muted">Not set</span>
                    <?php elseif ($p['price_min_cents'] === $p['price_max_cents']): ?><?= e(money((int) $p['price_min_cents'])) ?><?= (int) $p['on_sale'] ? ' <span class="pill pill--pending">Sale</span>' : '' ?>
                    <?php else: ?><?= e(money((int) $p['price_min_cents'])) ?> – <?= e(money((int) $p['price_max_cents'])) ?><?php endif ?>
                </td>
                <td data-label="Stock">
                    <?php if (!(int) $p['in_stock']): ?><span class="pill pill--cancelled">Out of stock</span>
                    <?php elseif ($p['stock_total'] !== null): ?><span class="num"><?= (int) $p['stock_total'] ?></span> <span class="muted small">in stock</span>
                    <?php else: ?><span class="pill pill--published">In stock</span><?php endif ?>
                </td>
                <td data-label="Sold" class="right num"><?= (int) $p['sales_count'] ?></td>
                <td data-label="Updated" class="right muted small nowrap"><?= e(gmdate('M j, Y', strtotime($p['updated_at']))) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->insert('vendor/_pager', ['page' => $list['page'], 'pages' => $list['pages'], 'base' => '/dashboard/products/', 'query' => array_filter(['status' => $status, 's' => $search])]) ?>
    <?php endif ?>
</section>
