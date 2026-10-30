<?php $this->layout('account/layout');
$states = config('us_states');
$fe = static fn (string $k) => $errors[$k] ?? null;
$err = static fn (string $k, string $label) => $fe($k) ? '<p class="field__error">' . e($label . ' ' . $fe($k)) . '</p>' : '';
?>
<?php if (!$enabled): ?>
    <p>The stylist program is paused right now. Please check back soon.</p>
<?php elseif ($stylist && $stylist['status'] === 'pending'): ?>
    <div class="points-hero"><p class="points-hero__label">Stylist program</p><p class="points-hero__value">Application received</p><p>We are reviewing your application for code <strong><?= e($stylist['code']) ?></strong> and will email you as soon as it is approved.</p></div>
<?php elseif ($stylist): ?>
    <div class="notice"><p>Your stylist account is <?= e($stylist['status']) ?>. Please <a href="<?= e(link_to('/contact-us/')) ?>">contact us</a> if you have questions.</p></div>
<?php else: ?>
    <div class="points-hero">
        <p class="points-hero__label">Stylist program</p>
        <p class="points-hero__value">Refer &amp; earn</p>
        <p>Share your personal link or code with clients. When they shop on The NiCa Collection, you earn a commission on every order, growing with your tier.</p>
    </div>
    <ul class="tier-list">
        <?php foreach ($tiers as $t): ?>
            <li><strong><?= e($t['name']) ?></strong><span><?= e(rtrim(rtrim(number_format((float) $t['rate'], 2), '0'), '.')) ?>% commission</span><small><?= (int) $t['min_cents'] > 0 ? 'from ' . e(money((int) $t['min_cents'])) . ' referred sales in 90 days' : 'when you join' ?></small></li>
        <?php endforeach ?>
    </ul>
    <h2 class="account-subtitle">Apply</h2>
    <form class="form" method="post" action="<?= e(link_to('/my-account/stylist/join')) ?>">
        <?= csrf_field() ?>
        <div class="form__grid form__grid--2">
            <div class="field<?= $fe('code') ? ' field--error' : '' ?>"><label for="f-code">Your referral code <span class="field__req">*</span></label><input type="text" id="f-code" name="code" value="<?= e(old('code', $suggested)) ?>" required minlength="4" maxlength="20" pattern="[A-Za-z0-9]+" autocomplete="off"><p class="field__hint">Letters and numbers, e.g. your name. Clients can type it at checkout.</p><?= $err('code', 'This code') ?></div>
            <div class="field"><label for="f-salon">Salon or business name</label><input type="text" id="f-salon" name="salon_name" value="<?= e(old('salon_name')) ?>" maxlength="150"></div>
            <div class="field<?= $fe('city') ? ' field--error' : '' ?>"><label for="f-city">City <span class="field__req">*</span></label><input type="text" id="f-city" name="city" value="<?= e(old('city')) ?>" required maxlength="100"></div>
            <div class="field<?= $fe('state') ? ' field--error' : '' ?>"><label for="f-state">State <span class="field__req">*</span></label><select id="f-state" name="state" required><option value="">Select…</option><?php foreach ($states as $k => $v): ?><option value="<?= e($k) ?>"<?= old('state') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></div>
            <div class="field"><label for="f-ig">Instagram</label><input type="text" id="f-ig" name="instagram" value="<?= e(old('instagram')) ?>" maxlength="100" placeholder="@yourhandle"></div>
        </div>
        <div class="field"><label for="f-about">Tell us about your work</label><textarea id="f-about" name="about" rows="3" maxlength="1000"><?= e(old('about')) ?></textarea></div>
        <label class="checkbox"><input type="checkbox" name="terms" value="1" required> I agree to share my link honestly: no spam, no paid ads on the NiCa name, and no ordering through my own link. <span class="field__req">*</span></label>
        <?= $err('terms', 'You must agree to the terms:') ?>
        <p><button class="btn btn--dark" type="submit">Apply to the program</button></p>
    </form>
<?php endif ?>
