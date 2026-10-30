<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$c = $coupon ?? [];
$fe = static fn (string $k) => $errors[$k] ?? null;
$v = static fn (string $k, string $d = '') => old($k, isset($c[$k]) && $c[$k] !== null ? (string) $c[$k] : $d);
$amount = $c ? rtrim(rtrim((string) $c['amount'], '0'), '.') : '';
$type = $v('type', 'percent');
$action = $c ? '/dashboard/coupons/' . $c['id'] . '/' : '/dashboard/coupons/new/';
$err = static fn (string $k, string $label) => $fe($k) ? '<p class="err">' . e($label . ' ' . $fe($k)) . '</p>' : '';
?>
<form method="post" action="<?= e(link_to($action)) ?>">
    <?= csrf_field() ?>
    <div class="grid grid--main">
        <div>
            <section class="card">
                <div class="form-grid">
                    <div class="fld<?= $fe('code') ? ' fld--error' : '' ?>"><label for="f-code">Coupon code</label><input type="text" id="f-code" name="code" value="<?= e($v('code')) ?>" maxlength="40" required placeholder="e.g. SUMMER15" autocomplete="off"><p class="hint">Letters, numbers, dashes. Customers type this at checkout.</p><?= $err('code', 'Code') ?></div>
                    <div class="fld"><label for="f-cdesc">Description <span class="muted">(only you see this)</span></label><input type="text" id="f-cdesc" name="description" value="<?= e($v('description')) ?>" maxlength="255"></div>
                    <div class="fld"><label for="f-type">Discount type</label>
                        <select id="f-type" name="type">
                            <option value="percent"<?= $type === 'percent' ? ' selected' : '' ?>>Percentage off</option>
                            <option value="fixed_cart"<?= $type === 'fixed_cart' ? ' selected' : '' ?>>Fixed amount off the order</option>
                            <option value="fixed_product"<?= $type === 'fixed_product' ? ' selected' : '' ?>>Fixed amount off each item</option>
                        </select></div>
                    <div class="fld<?= $fe('amount') ? ' fld--error' : '' ?>"><label for="f-camount">Amount</label><input type="number" step="0.01" min="0.01" id="f-camount" name="amount" value="<?= e(old('amount', $amount)) ?>" required><p class="hint">Percent, or dollars for fixed discounts.</p><?= $err('amount', 'Amount') ?></div>
                    <div class="fld<?= $fe('minimum') ? ' fld--error' : '' ?>"><label for="f-min">Minimum spend <span class="muted">(optional)</span></label><div class="prefix"><span>$</span><input type="text" inputmode="decimal" id="f-min" name="minimum" value="<?= e(old('minimum', $c && $c['minimum_cents'] ? number_format($c['minimum_cents'] / 100, 2, '.', '') : '')) ?>"></div></div>
                    <div class="fld<?= $fe('expires_at') ? ' fld--error' : '' ?>"><label for="f-exp">Expiry date <span class="muted">(optional)</span></label><input type="date" id="f-exp" name="expires_at" value="<?= e(old('expires_at', $c && $c['expires_at'] ? substr($c['expires_at'], 0, 10) : '')) ?>"></div>
                    <div class="fld"><label for="f-limit">Total uses <span class="muted">(optional)</span></label><input type="number" min="1" id="f-limit" name="usage_limit" value="<?= e($v('usage_limit')) ?>" placeholder="Unlimited"></div>
                    <div class="fld"><label for="f-ulimit">Uses per customer <span class="muted">(optional)</span></label><input type="number" min="1" id="f-ulimit" name="usage_limit_per_user" value="<?= e($v('usage_limit_per_user')) ?>" placeholder="Unlimited"></div>
                </div>
            </section>
            <section class="card">
                <h2>Products</h2>
                <p class="hint muted small">Leave all unticked to apply the coupon to every product in your store.</p>
                <div class="check-list">
                    <?php foreach ($products as $p): ?>
                        <label class="check"><input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $selected, true) ? ' checked' : '' ?>><?= e($p['name']) ?></label>
                    <?php endforeach ?>
                </div>
            </section>
        </div>
        <div class="editor-side">
            <section class="card">
                <h2>Options</h2>
                <label class="switch"><span>Active</span><input type="hidden" name="status" value="disabled"><input type="checkbox" name="status" value="active"<?= $v('status', 'active') === 'active' ? ' checked' : '' ?>></label>
                <label class="switch"><span>Can't combine with other coupons</span><input type="checkbox" name="individual_use" value="1"<?= $v('individual_use') === '1' ? ' checked' : '' ?>></label>
                <label class="switch"><span>Skip items already on sale</span><input type="checkbox" name="exclude_sale_items" value="1"<?= $v('exclude_sale_items') === '1' ? ' checked' : '' ?>></label>
                <label class="switch"><span>Also give free shipping</span><input type="checkbox" name="free_shipping" value="1"<?= $v('free_shipping') === '1' ? ' checked' : '' ?>></label>
            </section>
            <button class="btn btn--block" type="submit">Save coupon</button>
            <?php if ($c): ?><p><button class="btn btn--danger btn--block" type="submit" form="delete-coupon"><?= picon('trash') ?>Delete coupon</button></p><?php endif ?>
        </div>
    </div>
</form>
<?php if ($c): ?>
<form id="delete-coupon" method="post" action="<?= e(link_to('/dashboard/coupons/' . $c['id'] . '/delete')) ?>" data-confirm="Delete this coupon?"><?= csrf_field() ?></form>
<?php endif ?>
