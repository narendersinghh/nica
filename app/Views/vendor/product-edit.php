<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\VendorProducts;
$this->layout('layouts/panel');
$p = $product ?? [];
$posted = $input !== [];
$val = static fn (string $key, string $default = '') => $posted ? (string) ($input[$key] ?? $default) : (string) ($p[$key] ?? $default);
$type = $posted ? (($input['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple') : ($p['type'] ?? 'simple');
$varAttrs = $posted ? array_map('intval', (array) ($input['variation_attributes'] ?? [])) : $variationAttributes;
$selValues = $posted ? array_map('intval', (array) ($input['attribute_values'] ?? [])) : $selectedValues;
$selCats = $posted ? array_map('intval', (array) ($input['categories'] ?? [])) : $selectedCategories;
$selTags = $posted ? array_map('intval', (array) ($input['tags'] ?? [])) : $selectedTags;
$imageIds = $posted ? array_map('intval', (array) ($input['images'] ?? [])) : array_values(array_filter(array_merge([(int) ($p['featured_media_id'] ?? 0)], $images)));
$imageIds = array_values(array_unique(array_filter($imageIds)));
$err = static fn (string $k) => $formErrors[$k] ?? null;
$cents = static fn ($c) => $c === null || $c === '' ? '' : number_format((int) $c / 100, 2, '.', '');

// Variation rows: current combinations with saved (or re-posted) values.
$valuesByAttr = [];
$names = [];
foreach ($attributes as $a) {
    foreach ($a['values'] as $v) {
        $names[(int) $v['id']] = $v['name'];
        if (in_array((int) $v['id'], $selValues, true) && in_array((int) $a['id'], $varAttrs, true)) {
            $valuesByAttr[(int) $a['id']][] = (int) $v['id'];
        }
    }
}
$combos = VendorProducts::combinations($valuesByAttr);
$rowFor = static function (string $key) use ($posted, $input, $variants, $cents): array {
    if ($posted && isset($input['variants'][$key])) {
        $r = (array) $input['variants'][$key];
        return ['price' => $r['price'] ?? '', 'sale_price' => $r['sale_price'] ?? '', 'sku' => $r['sku'] ?? '', 'stock_qty' => $r['stock_qty'] ?? '', 'stock_status' => $r['stock_status'] ?? 'instock', 'enabled' => !empty($r['enabled'])];
    }
    $v = $variants[$key] ?? null;
    if ($v === null) {
        return ['price' => '', 'sale_price' => '', 'sku' => '', 'stock_qty' => '', 'stock_status' => 'instock', 'enabled' => true];
    }
    return [
        'price' => $cents($v['regular_price_cents'] ?: null), 'sale_price' => $cents($v['sale_price_cents']), 'sku' => (string) $v['sku'],
        'stock_qty' => (int) $v['manage_stock'] ? (string) (int) $v['stock_qty'] : '', 'stock_status' => $v['stock_status'], 'enabled' => (bool) $v['is_active'],
    ];
};
$simple = $rowFor('simple');
$stockOptions = ['instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Backorder'];
$action = $formAction ?? ($p ? '/dashboard/products/' . $p['id'] . '/' : '/dashboard/products/new/');
$isAdmin = ($area ?? 'vendor') === 'admin';
$catDepth = static fn (array $c) => substr_count($c['path'], '/');
?>
<?php $this->start('actions') ?>
<?php if ($p && $p['status'] === 'published'): ?><a class="btn btn--ghost" href="<?= e(link_to('/product/' . $p['slug'] . '/')) ?>" target="_blank" rel="noopener"><?= picon('eye') ?>View</a><?php endif ?>
<?php $this->stop() ?>

<?php if ($p && $p['status'] === 'rejected' && $p['review_note']): ?>
<div class="alert alert--warn"><?= picon('alert') ?><div><strong>Changes requested:</strong> <?= e($p['review_note']) ?></div></div>
<?php endif ?>

<form method="post" action="<?= e(link_to($action)) ?>" enctype="multipart/form-data" data-product-form novalidate>
    <?= csrf_field() ?>
    <div class="grid grid--main">
        <div>
            <section class="card">
                <div class="fld<?= $err('name') ? ' fld--error' : '' ?>">
                    <label for="f-name">Product name</label>
                    <input type="text" id="f-name" name="name" value="<?= e($val('name')) ?>" maxlength="200" required placeholder="e.g. Brazilian Body Wave Bundle">
                    <?php if ($err('name')): ?><p class="err"><?= e($err('name')) ?></p><?php endif ?>
                </div>
                <div class="fld">
                    <label for="f-short">Short description</label>
                    <textarea id="f-short" name="short_description" rows="3" maxlength="2000" placeholder="One or two sentences shown next to the price."><?= e($val('short_description')) ?></textarea>
                </div>
                <div class="fld">
                    <label for="f-desc">Full description</label>
                    <textarea id="f-desc" name="description" rows="9" placeholder="Hair origin, texture, length, care tips… Simple formatting like &lt;strong&gt; and lists is kept."><?= e($val('description')) ?></textarea>
                </div>
            </section>

            <section class="card" id="images">
                <div class="card__head"><h2>Images</h2><span class="muted small">The first image is the main photo. JPG, PNG or WebP up to 10 MB.</span></div>
                <?php if ($err('images')): ?><p class="err"><?= e($err('images')) ?></p><?php endif ?>
                <div class="media-grid" data-media-grid>
                    <?php foreach ($imageIds as $i => $mid): ?>
                        <div class="media-tile" data-media-tile>
                            <?= image($mid, ['size' => 'medium', 'alt' => '']) ?>
                            <?php if ($i === 0): ?><span class="media-tile__badge">Main</span><?php endif ?>
                            <input type="hidden" name="images[]" value="<?= (int) $mid ?>">
                            <div class="media-tile__bar">
                                <button type="button" data-media-first aria-label="Make main image"><?= picon('star') ?></button>
                                <button type="button" data-media-remove aria-label="Remove image"><?= picon('trash') ?></button>
                            </div>
                        </div>
                    <?php endforeach ?>
                    <label class="media-drop" data-media-drop>
                        <?= picon('upload') ?><span>Add images<br><span class="muted">or drop them here</span></span>
                        <input type="file" name="new_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-media-input>
                    </label>
                </div>
            </section>

            <section class="card" id="pricing">
                <div class="card__head">
                    <h2>Price &amp; stock</h2>
                    <div class="segmented" role="radiogroup" aria-label="Product type">
                        <label><input type="radio" name="type" value="simple" data-type-toggle<?= $type === 'simple' ? ' checked' : '' ?>>One option</label>
                        <label><input type="radio" name="type" value="variable" data-type-toggle<?= $type === 'variable' ? ' checked' : '' ?>>Lengths / colors</label>
                    </div>
                </div>

                    <?php foreach ($attributes as $a): ?>
                        <div class="fld">
                            <div class="toolbar toolbar--tight"><span class="fld-label"><?= e($a['name']) ?></span><label class="check small" data-type-only="variable"<?= $type === 'variable' ? '' : ' hidden' ?>><input type="checkbox" name="variation_attributes[]" value="<?= (int) $a['id'] ?>" data-variation-attr<?= in_array((int) $a['id'], $varAttrs, true) ? ' checked' : '' ?>>Use for variations</label></div>
                            <div class="tokens">
                                <?php foreach ($a['values'] as $v): ?>
                                    <label class="token"><input type="checkbox" name="attribute_values[]" value="<?= (int) $v['id'] ?>" data-attr="<?= (int) $a['id'] ?>" data-name="<?= e($v['name']) ?>"<?= in_array((int) $v['id'], $selValues, true) ? ' checked' : '' ?>><span><?php if ($v['swatch']): ?><i class="swatch-dot" data-swatch="<?= e($v['swatch']) ?>"></i><?php endif ?><?= e($v['name']) ?></span></label>
                                <?php endforeach ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                <p class="hint muted small" data-type-only="variable"<?= $type === 'variable' ? '' : ' hidden' ?>>Attributes not used for variations are shown as product details.</p>
                <div data-type-panel="simple"<?= $type === 'simple' ? '' : ' hidden' ?>>
                    <?php if ($err('price')): ?><p class="err"><?= e($err('price')) ?></p><?php endif ?>
                    <div class="form-grid">
                        <div class="fld"><label for="f-price">Price</label><div class="prefix"><span>$</span><input type="text" inputmode="decimal" id="f-price" name="variants[simple][price]" value="<?= e($simple['price']) ?>" placeholder="0.00"></div></div>
                        <div class="fld"><label for="f-sale">Sale price <span class="muted">(optional)</span></label><div class="prefix"><span>$</span><input type="text" inputmode="decimal" id="f-sale" name="variants[simple][sale_price]" value="<?= e($simple['sale_price']) ?>" placeholder="0.00"></div></div>
                        <div class="fld"><label for="f-sku">SKU <span class="muted">(optional)</span></label><input type="text" id="f-sku" name="variants[simple][sku]" value="<?= e($simple['sku']) ?>" maxlength="64"></div>
                        <div class="fld"><label for="f-qty">Quantity in stock</label><input type="number" min="0" max="99999" id="f-qty" name="variants[simple][stock_qty]" value="<?= e($simple['stock_qty']) ?>" placeholder="Not tracked"><p class="hint">Leave empty if you make items to order.</p></div>
                        <div class="fld"><label for="f-stock">Availability</label><select id="f-stock" name="variants[simple][stock_status]"><?php foreach ($stockOptions as $k => $l): ?><option value="<?= e($k) ?>"<?= $simple['stock_status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></div>
                    </div>
                </div>

                <div data-type-panel="variable"<?= $type === 'variable' ? '' : ' hidden' ?>>
                    <?php if ($err('attribute_values')): ?><p class="err"><?= e($err('attribute_values')) ?></p><?php endif ?>
                    <?php if ($err('variants')): ?><p class="err"><?= e($err('variants')) ?></p><?php endif ?>

                    <div class="bulk">
                        <label for="f-bulk">Set every price to</label>
                        <span class="prefix"><span>$</span><input type="text" inputmode="decimal" id="f-bulk" name="bulk_price" placeholder="0.00"></span>
                        <button type="button" class="btn btn--ghost btn--sm" data-bulk-apply>Apply</button>
                        <span class="muted">Empty prices keep a variation hidden.</span>
                    </div>
                    <div class="table-wrap">
                        <table class="variants">
                            <thead><tr><th><span class="sr-only">Enabled</span></th><th>Variation</th><th>Price ($)</th><th>Sale ($)</th><th>SKU</th><th>Qty</th><th>Availability</th></tr></thead>
                            <tbody data-variant-rows>
                            <?php foreach ($combos as $key => $values): $r = $rowFor($key); $label = implode(' / ', array_map(static fn ($vid) => $names[$vid] ?? '?', $values)); ?>
                                <tr data-key="<?= e($key) ?>"<?= $r['enabled'] ? '' : ' class="is-off"' ?>>
                                    <td><input type="checkbox" name="variants[<?= e($key) ?>][enabled]" value="1" aria-label="Offer <?= e($label) ?>"<?= $r['enabled'] ? ' checked' : '' ?> data-variant-enabled></td>
                                    <td class="vlabel"><?= e($label) ?></td>
                                    <td><input type="text" inputmode="decimal" name="variants[<?= e($key) ?>][price]" value="<?= e($r['price']) ?>" aria-label="<?= e($label) ?> price" data-variant-price></td>
                                    <td><input type="text" inputmode="decimal" name="variants[<?= e($key) ?>][sale_price]" value="<?= e($r['sale_price']) ?>" aria-label="<?= e($label) ?> sale price"></td>
                                    <td><input type="text" name="variants[<?= e($key) ?>][sku]" value="<?= e($r['sku']) ?>" maxlength="64" aria-label="<?= e($label) ?> SKU"></td>
                                    <td><input type="number" min="0" max="99999" name="variants[<?= e($key) ?>][stock_qty]" value="<?= e($r['stock_qty']) ?>" placeholder="—" aria-label="<?= e($label) ?> quantity"></td>
                                    <td><select name="variants[<?= e($key) ?>][stock_status]" aria-label="<?= e($label) ?> availability"><?php foreach ($stockOptions as $k => $l): ?><option value="<?= e($k) ?>"<?= $r['stock_status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                        <p class="muted small" data-variant-empty<?= $combos ? ' hidden' : '' ?>>Pick lengths and/or colors above to build the variation list.</p>
                    </div>
                </div>
            </section>

            <details class="card" id="seo"<?= $val('seo_title') !== '' || $val('seo_description') !== '' ? ' open' : '' ?>>
                <summary><h2 class="inline-form">Search engine listing</h2> <span class="muted small">Optional</span></summary>
                <div class="fld"><label for="f-seo-title">Title</label><input type="text" id="f-seo-title" name="seo_title" value="<?= e($val('seo_title')) ?>" maxlength="255" placeholder="Defaults to the product name"></div>
                <div class="fld"><label for="f-seo-desc">Description</label><textarea id="f-seo-desc" name="seo_description" rows="3" maxlength="500" placeholder="Defaults to the short description"><?= e($val('seo_description')) ?></textarea></div>
            </details>
        </div>

        <div class="editor-side">
            <section class="card">
                <div class="card__head"><h2>Status</h2><?php if ($p): ?><?= $this->insert('vendor/_status', ['status' => $p['status'], 'label' => VendorProducts::STATUS_LABELS[$p['status']] ?? $p['status']]) ?><?php endif ?></div>
                <?php if ($p && $p['status'] === 'archived'): ?>
                    <p class="small muted">This product is archived and hidden from the shop.</p>
                <?php elseif ($isAdmin): ?>
                    <p class="small muted">Editing <strong><?= e($vendor['store_name']) ?></strong>'s product as an admin. Publishing skips the review queue.</p>
                <?php elseif (App\Services\Settings::get('catalog.product_review')): ?>
                    <p class="small muted">Published products are checked by our team before they go live.</p>
                <?php else: ?>
                    <p class="small muted">Publishing makes the product visible in the shop right away<?= $vendor['status'] !== 'approved' ? ' (once your store is approved)' : '' ?>.</p>
                <?php endif ?>
                <div class="grid">
                    <button class="btn btn--block" type="submit" name="intent" value="publish"><?= picon('check') ?><?= $p && $p['status'] === 'published' ? 'Save changes' : 'Publish' ?></button>
                    <button class="btn btn--ghost btn--block" type="submit" name="intent" value="draft"><?= $p && $p['status'] === 'published' ? 'Unpublish (save as draft)' : 'Save draft' ?></button>
                </div>
            </section>

            <section class="card">
                <h2>Categories</h2>
                <?php if ($err('categories')): ?><p class="err"><?= e($err('categories')) ?></p><?php endif ?>
                <div class="check-list check-list--single">
                    <?php foreach ($categories as $c): $d = $catDepth($c); ?>
                        <label class="check<?= $d ? ' indent' . ($d > 1 ? '-2' : '') : '' ?>"><input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $selCats, true) ? ' checked' : '' ?>><?= e($c['name']) ?></label>
                    <?php endforeach ?>
                </div>
                <p class="hint muted small">The first one you tick is the main category.</p>
            </section>

            <section class="card">
                <h2>Hair texture</h2>
                <div class="tokens">
                    <?php foreach ($tags as $t): ?>
                        <label class="token"><input type="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>"<?= in_array((int) $t['id'], $selTags, true) ? ' checked' : '' ?>><span><?= e($t['name']) ?></span></label>
                    <?php endforeach ?>
                </div>
            </section>

            <section class="card">
                <h2>Shipping</h2>
                <div class="fld">
                    <label for="f-ship">Shipping class</label>
                    <select id="f-ship" name="shipping_class">
                        <?php foreach ($shippingClasses as $k => $l): ?><option value="<?= e($k) ?>"<?= $val('shipping_class') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
                    </select>
                    <p class="hint">Free shipping applies automatically on orders over <?= e(money((int) App\Services\Settings::get('commerce.shipping.free_shipping.min_cents'))) ?>.</p>
                </div>
            </section>
        </div>
    </div>
    <div class="sticky-bar no-print">
        <a class="btn btn--ghost" href="<?= e(link_to($back[1] ?? '/dashboard/products/')) ?>">Cancel</a>
        <button class="btn btn--ghost" type="submit" name="intent" value="draft">Save draft</button>
        <button class="btn" type="submit" name="intent" value="publish"><?= $p && $p['status'] === 'published' ? 'Save changes' : 'Publish' ?></button>
    </div>
</form>

<?php if ($p && $isAdmin): ?>
<section class="card" id="review">
    <h2>Moderation</h2>
    <div class="grid grid--2">
        <form method="post" action="<?= e(link_to('/admin/products/' . $p['id'] . '/reject')) ?>">
            <?= csrf_field() ?>
            <div class="fld"><label for="f-rnote">Ask the vendor for changes</label><textarea id="f-rnote" name="note" rows="3" maxlength="500" placeholder="What needs fixing? The vendor is emailed."><?= e((string) $p['review_note']) ?></textarea></div>
            <button class="btn btn--danger btn--sm" type="submit">Request changes (unpublish)</button>
        </form>
        <div>
            <?php if ($p['status'] !== 'published'): ?><form method="post" action="<?= e(link_to('/admin/products/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?><p><button class="btn btn--sm" type="submit"><?= picon('check') ?>Approve and publish</button></p></form><?php endif ?>
            <form method="post" action="<?= e(link_to('/admin/products/' . $p['id'] . ((int) $p['nica_score_verified'] ? '/unverify' : '/verify'))) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="/admin/products/<?= (int) $p['id'] ?>/"><p><button class="btn btn--ghost btn--sm" type="submit"><?= (int) $p['nica_score_verified'] ? 'Remove NiCa Score verification' : 'Mark NiCa Score verified' ?></button></p></form>
            <form method="post" action="<?= e(link_to('/admin/products/' . $p['id'] . ((int) $p['is_featured'] ? '/unfeature' : '/feature'))) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="/admin/products/<?= (int) $p['id'] ?>/"><p><button class="btn btn--ghost btn--sm" type="submit"><?= (int) $p['is_featured'] ? 'Remove from featured' : 'Feature on the home page' ?></button></p></form>
        </div>
    </div>
</section>
<?php endif ?>

<?php if ($p && !$isAdmin): ?>
<section class="card">
    <h2>More actions</h2>
    <div class="toolbar">
        <form method="post" action="<?= e(link_to('/dashboard/products/' . $p['id'] . '/duplicate')) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit"><?= picon('copy') ?>Duplicate</button></form>
        <?php if ($p['status'] === 'archived'): ?>
            <form method="post" action="<?= e(link_to('/dashboard/products/' . $p['id'] . '/restore')) ?>"><?= csrf_field() ?><button class="btn btn--ghost btn--sm" type="submit">Restore</button></form>
        <?php else: ?>
            <form method="post" action="<?= e(link_to('/dashboard/products/' . $p['id'] . '/archive')) ?>" data-confirm="Archive this product? It will be hidden from the shop."><?= csrf_field() ?><button class="btn btn--danger btn--sm" type="submit"><?= picon('trash') ?>Archive</button></form>
        <?php endif ?>
    </div>
</section>
<?php endif ?>
