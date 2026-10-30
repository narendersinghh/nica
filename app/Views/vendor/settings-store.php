<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Vendor\SettingsController;
$this->layout('layouts/panel');
$fe = static fn (string $k) => $errors[$k] ?? null;
$v = static fn (string $k, ?string $current) => old($k, (string) $current);
$aboutText = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $vendor['about'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$field = static function (string $name, string $label, ?string $value, array $opts = []) use ($fe): string {
    $type = $opts['type'] ?? 'text';
    $id = 'f-' . $name;
    $html = '<div class="fld' . ($fe($name) ? ' fld--error' : '') . (!empty($opts['full']) ? ' full' : '') . '"><label for="' . $id . '">' . e($label) . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea id="' . $id . '" name="' . e($name) . '" rows="' . (int) ($opts['rows'] ?? 4) . '" maxlength="' . (int) ($opts['max'] ?? 5000) . '">' . e(old($name, (string) $value)) . '</textarea>';
    } else {
        $html .= '<input type="' . e($type) . '" id="' . $id . '" name="' . e($name) . '" value="' . e(old($name, (string) $value)) . '"' . (isset($opts['max']) ? ' maxlength="' . (int) $opts['max'] . '"' : '') . (!empty($opts['placeholder']) ? ' placeholder="' . e($opts['placeholder']) . '"' : '') . '>';
    }
    if (!empty($opts['hint'])) {
        $html .= '<p class="hint">' . e($opts['hint']) . '</p>';
    }
    if ($fe($name)) {
        $html .= '<p class="err">' . e($label . ' ' . $fe($name)) . '</p>';
    }
    return $html . '</div>';
};
?>
<?php $this->start('actions') ?>
<a class="btn btn--ghost" href="<?= e(link_to('/store/' . $vendor['slug'] . '/')) ?>" target="_blank" rel="noopener"><?= picon('eye') ?>View store</a>
<?php $this->stop() ?>

<form method="post" action="<?= e(link_to('/dashboard/settings/store/')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="grid grid--main">
        <div>
            <section class="card">
                <h2>Store profile</h2>
                <div class="form-grid">
                    <?= $field('store_name', 'Store name', $vendor['store_name'], ['max' => 100]) ?>
                    <?= $field('tagline', 'Tagline', $vendor['tagline'], ['max' => 160, 'placeholder' => 'A short line under your store name']) ?>
                    <?= $field('about', 'About your store', $aboutText, ['type' => 'textarea', 'rows' => 6, 'full' => true]) ?>
                </div>
                <p class="hint muted small">Your store address: <?= e(url('/store/' . $vendor['slug'] . '/')) ?> (contact us to change it).</p>
            </section>

            <section class="card">
                <h2>Contact &amp; location</h2>
                <div class="form-grid">
                    <?= $field('public_email', 'Public email', $vendor['public_email'], ['type' => 'email']) ?>
                    <?= $field('phone', 'Phone', $vendor['phone'], ['type' => 'tel', 'max' => 40]) ?>
                    <?= $field('street_1', 'Street address', $address['street_1'] ?? '', ['max' => 150, 'full' => true]) ?>
                    <?= $field('street_2', 'Apartment, suite (optional)', $address['street_2'] ?? '', ['max' => 150, 'full' => true]) ?>
                    <?= $field('city', 'City', $address['city'] ?? '', ['max' => 100]) ?>
                    <?= $field('state', 'State', $address['state'] ?? '', ['max' => 100]) ?>
                    <?= $field('zip', 'ZIP code', $address['zip'] ?? '', ['max' => 20]) ?>
                </div>
                <p class="hint muted small">Shown on packing slips and invoices.</p>
            </section>

            <section class="card">
                <h2>Social links</h2>
                <div class="form-grid">
                    <?php foreach (SettingsController::SOCIAL as $key => $label): ?>
                        <?= $field('social_' . $key, $label, $social[$key] ?? '', ['type' => 'url', 'placeholder' => 'https://']) ?>
                    <?php endforeach ?>
                </div>
            </section>

            <section class="card">
                <h2>Policies</h2>
                <?= $field('shipping_policy', 'Shipping policy', $vendor['shipping_policy'], ['type' => 'textarea', 'rows' => 4]) ?>
                <?= $field('refund_policy', 'Returns & refund policy', $vendor['refund_policy'], ['type' => 'textarea', 'rows' => 4]) ?>
            </section>
        </div>

        <div class="editor-side">
            <section class="card">
                <h2>Logo</h2>
                <div class="media-grid">
                    <?php if ($vendor['logo_media_id']): ?><div class="media-tile"><?= image((int) $vendor['logo_media_id'], ['size' => 'thumbnail', 'alt' => 'Current logo']) ?></div><?php endif ?>
                    <label class="media-drop" data-media-drop><?= picon('upload') ?><span><?= $vendor['logo_media_id'] ? 'Replace' : 'Upload' ?> logo</span><input type="file" name="logo" accept="image/jpeg,image/png,image/webp" data-single-preview></label>
                </div>
                <?php if ($vendor['logo_media_id']): ?><label class="check small"><input type="checkbox" name="remove_logo" value="1">Remove logo</label><?php endif ?>
                <p class="hint muted small">Square, at least 300 × 300 px.</p>
            </section>
            <section class="card">
                <h2>Banner</h2>
                <?php if ($vendor['banner_media_id']): ?><div class="media-tile media-tile--wide"><?= image((int) $vendor['banner_media_id'], ['size' => 'medium_large', 'alt' => 'Current banner']) ?></div><?php endif ?>
                <label class="media-drop media-drop--wide" data-media-drop><?= picon('upload') ?><span><?= $vendor['banner_media_id'] ? 'Replace' : 'Upload' ?> banner</span><input type="file" name="banner" accept="image/jpeg,image/png,image/webp" data-single-preview></label>
                <?php if ($vendor['banner_media_id']): ?><label class="check small"><input type="checkbox" name="remove_banner" value="1">Remove banner</label><?php endif ?>
                <p class="hint muted small">Wide image, about 1600 × 400 px.</p>
            </section>
            <section class="card" id="vacation">
                <label class="switch"><span><strong>Vacation mode</strong><br><span class="muted small">Hide your products from the shop while you are away.</span></span><input type="checkbox" name="vacation_mode" value="1"<?= (int) $vendor['vacation_mode'] ? ' checked' : '' ?>></label>
            </section>
        </div>
    </div>
    <div class="sticky-bar"><button class="btn" type="submit">Save settings</button></div>
</form>
