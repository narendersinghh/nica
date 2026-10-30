<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$fe = static fn (string $k) => $errors[$k] ?? null;
$d = static fn ($cents) => $cents === null ? '' : number_format((int) $cents / 100, 2, '.', '');
$f = static function (string $name, string $label, string $value, array $o = []) use ($fe): string {
    $id = 'f-' . $name;
    return '<div class="fld' . ($fe($name) ? ' fld--error' : '') . (!empty($o['full']) ? ' full' : '') . '"><label for="' . $id . '">' . e($label) . '</label>'
        . (!empty($o['prefix']) ? '<div class="prefix"><span>' . e($o['prefix']) . '</span>' : '')
        . '<input type="' . e($o['type'] ?? 'text') . '" id="' . $id . '" name="' . e($name) . '" value="' . e(old($name, $value)) . '"' . (isset($o['step']) ? ' step="' . e($o['step']) . '"' : '') . (isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '') . '>'
        . (!empty($o['prefix']) ? '</div>' : '')
        . (!empty($o['hint']) ? '<p class="hint">' . e($o['hint']) . '</p>' : '')
        . ($fe($name) ? '<p class="err">' . e($label . ' ' . $fe($name)) . '</p>' : '') . '</div>';
};
$canEdit = App\Http\Middleware\AdminAccess::can('settings');
?>
<form method="post" action="<?= e(link_to('/admin/settings/')) ?>">
    <?= csrf_field() ?>
    <fieldset class="plain"<?= $canEdit ? '' : ' disabled' ?>>
    <div class="grid grid--main">
        <div>
            <section class="card">
                <h2>Marketplace money</h2>
                <div class="form-grid">
                    <?= $f('commission_rate', 'Commission (%)', (string) $commerce['commission_rate'], ['type' => 'number', 'step' => '0.01', 'hint' => 'Taken from each vendor sale (items only; shipping and tax go to the vendor). Stores can have their own rate.']) ?>
                    <?= $f('payout_threshold', 'Minimum payout', $d($commerce['payout_threshold_cents']), ['prefix' => '$', 'hint' => 'Smallest balance a vendor can request.']) ?>
                </div>
            </section>
            <section class="card">
                <h2>Tax</h2>
                <label class="check"><input type="checkbox" name="tax_enabled" value="1"<?= !empty($commerce['tax']['enabled']) ? ' checked' : '' ?>>Charge sales tax</label>
                <div class="form-grid"><?= $f('tax_rate', 'Tax rate (%)', (string) $commerce['tax']['rate'], ['type' => 'number', 'step' => '0.001', 'hint' => 'One rate for all orders, on items only.']) ?></div>
            </section>
            <section class="card">
                <h2>Shipping</h2>
                <p class="small muted">Charged per store in the cart. Each shipping class in a store's package is charged once.</p>
                <div class="form-grid">
                    <?= $f('flat_rate', 'Standard flat rate', $d($commerce['shipping']['flat_rate']['no_class_cents']), ['prefix' => '$']) ?>
                    <?= $f('free_shipping_min', 'Free shipping from', $d($commerce['shipping']['free_shipping']['min_cents'] ?? null), ['prefix' => '$', 'hint' => 'Per store package. Leave empty to turn free shipping off.']) ?>
                    <?php foreach ((array) $commerce['shipping']['flat_rate']['class_cents'] as $slug => $cents): ?>
                        <div class="fld"><label for="f-cls-<?= e($slug) ?>">Class “<?= e($slug) ?>”</label><div class="prefix"><span>$</span><input type="text" id="f-cls-<?= e($slug) ?>" name="class_cents[<?= e($slug) ?>]" value="<?= e($d($cents)) ?>"></div></div>
                    <?php endforeach ?>
                    <?= $f('stock_hold_minutes', 'Hold stock for unpaid orders (minutes)', (string) $commerce['stock_hold_minutes'], ['type' => 'number']) ?>
                </div>
            </section>
            <section class="card">
                <h2>Points &amp; rewards</h2>
                <label class="check"><input type="checkbox" name="points_enabled" value="1"<?= !empty($points['enabled']) ? ' checked' : '' ?>>Points program is on</label>
                <div class="form-grid">
                    <?= $f('signup_points', 'Points for signing up', (string) $points['signup_points'], ['type' => 'number']) ?>
                    <?= $f('points_per_dollar_redeemed', 'Points per $1 off', (string) $points['points_per_dollar_redeemed'], ['type' => 'number']) ?>
                    <?= $f('points_per_dollar_spent', 'Points earned per $1 spent', (string) $points['points_per_dollar_spent'], ['type' => 'number', 'hint' => '0 = no points for purchases (as on the old site).']) ?>
                </div>
            </section>
            <section class="card" id="stylists">
                <h2>Stylist program</h2>
                <label class="check"><input type="checkbox" name="ref_enabled" value="1"<?= !empty($referrals['enabled']) ? ' checked' : '' ?>>Program is open (links and codes earn commission)</label>
                <label class="check"><input type="checkbox" name="ref_auto_approve" value="1"<?= !empty($referrals['auto_approve']) ? ' checked' : '' ?>>Approve new stylists automatically</label>
                <div class="form-grid">
                    <?= $f('ref_cookie_days', 'Link remembered for (days)', (string) $referrals['cookie_days'], ['type' => 'number']) ?>
                    <?= $f('ref_threshold', 'Minimum stylist payout', $d($referrals['payout_threshold_cents']), ['prefix' => '$']) ?>
                    <?= $f('ref_window_days', 'Tier based on sales over (days)', (string) $referrals['tier_window_days'], ['type' => 'number']) ?>
                </div>
                <?php if ($fe('tiers')): ?><p class="err"><?= e($fe('tiers')) ?></p><?php endif ?>
                <table class="table tiers-table">
                    <thead><tr><th>Tier</th><th>From referred sales ($)</th><th>Commission (%)</th></tr></thead>
                    <tbody>
                    <?php foreach ($referrals['tiers'] as $i => $t): ?>
                        <tr><td><strong><?= e($t['name']) ?></strong></td>
                            <td><input class="input" type="number" step="0.01" min="0" name="tier_min[<?= e($t['key']) ?>]" value="<?= e($d($t['min_cents'])) ?>"<?= $i === 0 ? ' readonly' : '' ?> aria-label="<?= e($t['name']) ?> threshold"></td>
                            <td><input class="input" type="number" step="0.01" min="0" max="50" name="tier_rate[<?= e($t['key']) ?>]" value="<?= e((string) $t['rate']) ?>" aria-label="<?= e($t['name']) ?> rate"></td></tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                <p class="hint muted small">Commission is on items after discounts (not tax or shipping), paid by the marketplace out of its own commission and never more than it. It becomes withdrawable when the order part is completed and is taken back on refunds.</p>
            </section>
            <section class="card" id="integrations">
                <h2>Integrations</h2>
                <p class="small muted">Off until an ID is entered. Tracking loads only after a visitor accepts cookies (a consent banner appears automatically once one is on), and never slows the first paint.</p>
                <div class="form-grid">
                    <?= $f('ga4_id', 'Google Analytics 4 measurement ID', (string) ($integrations['ga4']['measurement_id'] ?? ''), ['placeholder' => 'G-XXXXXXXXXX']) ?>
                    <?= $f('meta_pixel_id', 'Meta (Facebook) Pixel ID', (string) ($integrations['meta_pixel']['pixel_id'] ?? ''), ['placeholder' => '15–16 digits']) ?>
                    <?= $f('klaviyo_key', 'Klaviyo public API key', (string) ($integrations['klaviyo']['public_key'] ?? ''), ['placeholder' => '6 characters']) ?>
                </div>
                <label class="check"><input type="checkbox" name="google_feed" value="1"<?= !empty($integrations['google_merchant']['enabled']) ? ' checked' : '' ?>>Publish the Google Merchant Center product feed</label>
                <p class="hint muted small">Feed address for Merchant Center: <code><?= e($feedUrl) ?></code>. Meta Conversions API, Klaviyo server events and ShipStation need credentials in the server's .env file and are ready to connect later.</p>
            </section>
            <section class="card">
                <h2>Store front</h2>
                <div class="form-grid">
                    <?= $f('announcement', 'Announcement bar', (string) $site['announcement'], ['full' => true, 'hint' => 'The line at the very top of every page. Keep the free-shipping amount in line with the setting above.']) ?>
                    <?= $f('contact_email', 'Contact email', (string) $site['contact']['email'], ['type' => 'email', 'hint' => 'Shown on the site; also receives order and vendor notifications.']) ?>
                    <?= $f('contact_phone', 'Contact phone', (string) $site['contact']['phone']) ?>
                </div>
            </section>
        </div>
        <div class="editor-side">
            <section class="card">
                <h2>Products</h2>
                <label class="switch"><span><strong>Review new vendor products</strong><br><span class="muted small">Off on the old site: vendor products go live at once.</span></span><input type="checkbox" name="product_review" value="1"<?= !empty($catalog['product_review']) ? ' checked' : '' ?>></label>
            </section>
            <section class="card">
                <h2>System</h2>
                <dl class="kv kv--left small">
                    <?php foreach ($system as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach ?>
                </dl>
                <p class="hint muted small">Payment keys, email server and domain settings live in the server's .env file so they never pass through the browser.</p>
            </section>
            <?php if ($canEdit): ?><button class="btn btn--block" type="submit">Save settings</button><?php endif ?>
        </div>
    </div>
    </fieldset>
</form>
