<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\VendorProducts;
$this->layout('layouts/panel');
$labels = VendorProducts::STATUS_LABELS;
$query = array_filter(['vendor' => $vendorId ?: null, 's' => $search, 'stock' => $lowStock ? 'low' : null]);
$link = static fn (?string $s) => link_to('/admin/products/') . '?' . http_build_query(array_filter(['status' => $s] + $query));
$return = '/admin/products/?' . http_build_query(array_filter(['status' => $status] + $query));
?>
<div class="toolbar">
    <nav class="chips" aria-label="Filter by status">
        <a href="<?= e($link(null)) ?>"<?= $status === null ? ' aria-current="page"' : '' ?>>All<span><?= array_sum($counts) - $counts['archived'] ?></span></a>
        <?php foreach ($labels as $k => $l): if (!$counts[$k] && $status !== $k) continue; ?>
            <a href="<?= e($link($k)) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><span><?= (int) $counts[$k] ?></span></a>
        <?php endforeach ?>
    </nav>
    <form class="search" method="get" action="<?= e(link_to('/admin/products/')) ?>" role="search">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
        <label class="sr-only" for="pv">Vendor</label>
        <select id="pv" name="vendor" class="bare" data-autosubmit><option value="">All vendors</option><?php foreach ($vendors as $v): ?><option value="<?= (int) $v['id'] ?>"<?= $vendorId === (int) $v['id'] ? ' selected' : '' ?>><?= e($v['store_name']) ?></option><?php endforeach ?></select>
        <?= picon('search') ?><label class="sr-only" for="ps">Search</label><input id="ps" type="search" name="s" value="<?= e($search) ?>" placeholder="Name or SKU">
    </form>
</div>
<?php if ($lowStock): ?><p class="small">Showing products with 3 or fewer items left in a variation. <a href="<?= e(link_to('/admin/products/')) ?>">Show all</a></p><?php endif ?>
<section class="card card--flush">
    <?php if (!$rows): ?><div class="empty"><?= picon('box') ?>No products match.</div><?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>Product</th><th>Vendor</th><th>Status</th><th>Price</th><th class="right">Sold</th><th>Flags</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p): $base = '/admin/products/' . $p['id'] . '/'; ?>
            <tr>
                <td class="prod-col"><div class="prod-cell"><span class="thumb"><?php if ($p['featured_media_id']): ?><?= image((int) $p['featured_media_id'], ['size' => 'thumbnail', 'width' => 44, 'height' => 44, 'alt' => '']) ?><?php endif ?></span><div><a href="<?= e(link_to($base)) ?>"><strong><?= e($p['name']) ?></strong></a><?php if ($p['status'] === 'published'): ?><div class="row-actions"><a href="<?= e(link_to('/product/' . $p['slug'] . '/')) ?>" target="_blank" rel="noopener">View in shop</a></div><?php endif ?></div></div></td>
                <td data-label="Vendor"><a href="<?= e(link_to('/admin/vendors/' . $p['vendor_id'] . '/')) ?>"><?= e($p['store_name']) ?></a></td>
                <td data-label="Status"><span class="pill pill--<?= e($p['status']) ?>"><?= e($labels[$p['status']]) ?></span><?php if (!(int) $p['in_stock']): ?> <span class="pill pill--cancelled">Out of stock</span><?php endif ?></td>
                <td data-label="Price" class="num nowrap"><?= (int) $p['price_max_cents'] ? e(money((int) $p['price_min_cents'])) . ($p['price_min_cents'] !== $p['price_max_cents'] ? ' – ' . e(money((int) $p['price_max_cents'])) : '') : '<span class="muted">—</span>' ?></td>
                <td data-label="Sold" class="right num"><?= (int) $p['sales_count'] ?></td>
                <td data-label="Flags" class="nowrap">
                    <?php foreach ([['is_featured', 'feature', 'unfeature', 'Featured'], ['nica_score_verified', 'verify', 'unverify', 'Verified']] as [$col, $on, $off, $label]): ?>
                        <form class="inline-form" method="post" action="<?= e(link_to($base . ((int) $p[$col] ? $off : $on))) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><button class="pill<?= (int) $p[$col] ? ' pill--published' : '' ?> pill-btn" type="submit" title="<?= (int) $p[$col] ? 'Click to remove' : 'Click to set' ?>"><?= (int) $p[$col] ? '✓ ' : '+ ' ?><?= $label ?></button></form>
                    <?php endforeach ?>
                </td>
                <td class="nowrap">
                    <?php if ($p['status'] === 'pending'): ?>
                        <form class="inline-form" method="post" action="<?= e(link_to($base . 'approve')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><button class="btn btn--sm" type="submit">Approve</button></form>
                        <a class="btn btn--ghost btn--sm" href="<?= e(link_to($base . '#review')) ?>">Reject…</a>
                    <?php else: ?><a class="btn btn--ghost btn--sm" href="<?= e(link_to($base)) ?>">Edit</a><?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->insert('vendor/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/products/', 'query' => array_filter(['status' => $status] + $query)]) ?>
    <?php endif ?>
</section>
