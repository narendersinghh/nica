<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\CouponsController;
$this->layout('layouts/panel');
$fe = static fn (string $k) => $errors[$k] ?? null;
$v = static fn (string $k, string $d = '') => old($k, isset($c[$k]) && $c[$k] !== null ? (string) $c[$k] : $d);
$money = static fn (?string $k) => $c && $c[$k] ? number_format($c[$k] / 100, 2, '.', '') : '';
$err = static fn (string $k, string $label) => $fe($k) ? '<p class="err">' . e($label . ' ' . $fe($k)) . '</p>' : '';
$checked = static fn (string $k) => old($k, $c ? (string) $c[$k] : '0') === '1' ? ' checked' : '';
$type = $v('type', 'percent');
?>
<form method="post" action="<?= e(link_to($c ? '/admin/coupons/' . $c['id'] . '/' : '/admin/coupons/new/')) ?>">
    <?= csrf_field() ?>
    <div class="grid grid--main">
        <div>
            <section class="card">
                <div class="form-grid">
                    <div class="fld<?= $fe('code') ? ' fld--error' : '' ?>"><label for="f-code">Code</label><input type="text" id="f-code" name="code" value="<?= e($v('code')) ?>" required maxlength="40" autocomplete="off"><?= $err('code', 'Code') ?></div>
                    <div class="fld"><label for="f-desc">Internal description</label><input type="text" id="f-desc" name="description" value="<?= e($v('description')) ?>" maxlength="255"></div>
                    <div class="fld"><label for="f-type">Type</label><select id="f-type" name="type"><?php foreach (['percent' => 'Percentage off', 'fixed_cart' => 'Fixed amount off the order', 'fixed_product' => 'Fixed amount off each item'] as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></div>
                    <div class="fld<?= $fe('amount') ? ' fld--error' : '' ?>"><label for="f-amt">Amount</label><input type="number" step="0.01" min="0" id="f-amt" name="amount" value="<?= e(old('amount', $c ? rtrim(rtrim((string) $c['amount'], '0'), '.') : '')) ?>" required><p class="hint">0 with "free shipping" makes a free-shipping code.</p><?= $err('amount', 'Amount') ?></div>
                    <div class="fld"><label for="f-min">Minimum spend ($)</label><input type="text" inputmode="decimal" id="f-min" name="minimum" value="<?= e(old('minimum', $money('minimum_cents'))) ?>"></div>
                    <div class="fld"><label for="f-max">Maximum spend ($)</label><input type="text" inputmode="decimal" id="f-max" name="maximum" value="<?= e(old('maximum', $money('maximum_cents'))) ?>"></div>
                    <div class="fld"><label for="f-ul">Total uses</label><input type="number" min="1" id="f-ul" name="usage_limit" value="<?= e($v('usage_limit')) ?>" placeholder="Unlimited"></div>
                    <div class="fld"><label for="f-uu">Uses per customer</label><input type="number" min="1" id="f-uu" name="usage_limit_per_user" value="<?= e($v('usage_limit_per_user')) ?>" placeholder="Unlimited"></div>
                    <div class="fld"><label for="f-st">Starts</label><input type="date" id="f-st" name="starts_at" value="<?= e(old('starts_at', $c && $c['starts_at'] ? substr($c['starts_at'], 0, 10) : '')) ?>"></div>
                    <div class="fld"><label for="f-ex">Expires</label><input type="date" id="f-ex" name="expires_at" value="<?= e(old('expires_at', $c && $c['expires_at'] ? substr($c['expires_at'], 0, 10) : '')) ?>"></div>
                </div>
            </section>
            <section class="card">
                <h2>Limits</h2>
                <p class="hint muted small">Leave everything unticked for no limit.</p>
                <details class="fld"<?= $sel['vendors'] ? ' open' : '' ?>><summary class="fld-label">Only these stores</summary><div class="check-list"><?php foreach ($vendors as $x): ?><label class="check"><input type="checkbox" name="vendor_ids[]" value="<?= (int) $x['id'] ?>"<?= in_array((int) $x['id'], $sel['vendors'], true) ? ' checked' : '' ?>><?= e($x['store_name']) ?></label><?php endforeach ?></div></details>
                <details class="fld"<?= $sel['categories'] ? ' open' : '' ?>><summary class="fld-label">Only these categories</summary><div class="check-list"><?php foreach ($categories as $x): ?><label class="check<?= substr_count($x['path'], '/') ? ' indent' : '' ?>"><input type="checkbox" name="category_ids[]" value="<?= (int) $x['id'] ?>"<?= in_array((int) $x['id'], $sel['categories'], true) ? ' checked' : '' ?>><?= e($x['name']) ?></label><?php endforeach ?></div></details>
                <details class="fld"<?= $sel['products'] ? ' open' : '' ?>><summary class="fld-label">Only these products</summary><div class="check-list check-list--single"><?php foreach ($products as $x): ?><label class="check"><input type="checkbox" name="product_ids[]" value="<?= (int) $x['id'] ?>"<?= in_array((int) $x['id'], $sel['products'], true) ? ' checked' : '' ?>><?= e($x['name']) ?> <span class="muted small">· <?= e($x['store_name']) ?></span></label><?php endforeach ?></div></details>
                <div class="fld"><label for="f-em">Only these customer emails</label><textarea id="f-em" name="allowed_emails" rows="3" placeholder="One per line"><?= e(old('allowed_emails', $emails)) ?></textarea></div>
            </section>
        </div>
        <div class="editor-side">
            <section class="card">
                <h2>Ownership</h2>
                <div class="fld"><label for="f-own">Coupon belongs to</label><select id="f-own" name="vendor_id"><option value="">The marketplace</option><?php foreach ($vendors as $x): ?><option value="<?= (int) $x['id'] ?>"<?= (int) $v('vendor_id') === (int) $x['id'] ? ' selected' : '' ?>><?= e($x['store_name']) ?> (store coupon)</option><?php endforeach ?></select></div>
                <div class="fld"><label for="f-cb">Who pays for the discount</label><select id="f-cb" name="cost_bearer"><?php foreach (CouponsController::BEARERS as $k => $l): ?><option value="<?= $k ?>"<?= $v('cost_bearer', 'platform') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><p class="hint">Store coupons are always paid by the store.</p></div>
            </section>
            <section class="card">
                <input type="hidden" name="status" value="disabled">
                <label class="switch"><span>Active</span><input type="checkbox" name="status" value="active"<?= $v('status', 'active') === 'active' ? ' checked' : '' ?>></label>
                <label class="switch"><span>Free shipping</span><input type="checkbox" name="free_shipping" value="1"<?= $checked('free_shipping') ?>></label>
                <label class="switch"><span>Can't combine with other coupons</span><input type="checkbox" name="individual_use" value="1"<?= $checked('individual_use') ?>></label>
                <label class="switch"><span>Skip sale items</span><input type="checkbox" name="exclude_sale_items" value="1"<?= $checked('exclude_sale_items') ?>></label>
            </section>
            <button class="btn btn--block" type="submit">Save coupon</button>
            <?php if ($c): ?><p><button class="btn btn--danger btn--block" type="submit" form="del-coupon">Delete coupon</button></p><?php endif ?>
        </div>
    </div>
</form>
<?php if ($c): ?><form id="del-coupon" method="post" action="<?= e(link_to('/admin/coupons/' . $c['id'] . '/delete')) ?>" data-confirm="Delete this coupon?"><?= csrf_field() ?></form><?php endif ?>
