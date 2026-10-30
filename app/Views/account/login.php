<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-account']); ?>
<section class="page-banner">
    <div class="container">
        <p class="eyebrow eyebrow--light"><?= icon('user') ?><span>Your Dashboard</span></p>
        <h1>My account</h1>
    </div>
</section>
<section class="section-pad section--soft">
    <div class="container">
        <?php if ($signupPoints > 0): ?><div class="notice"><?= icon('check') ?> You will get <?= (int) $signupPoints ?> points for a successful signup.</div><?php endif ?>
        <?php if ($notice): ?><div class="notice notice--success" role="status"><?= e($notice) ?></div><?php endif ?>
        <div class="auth-grid">
            <div>
                <h2 class="auth-title">Login</h2>
                <form class="form cart-panel" method="post" action="<?= e(link_to('/my-account/login')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <?php if ($loginError): ?><div class="notice notice--error" role="alert"><?= e($loginError) ?></div><?php endif ?>
                    <?= $this->insert('partials/field', ['name' => 'username', 'label' => 'Username or email address', 'required' => true, 'autocomplete' => 'username', 'errors' => []]) ?>
                    <div class="field">
                        <label for="f-password">Password <span class="field__req" aria-hidden="true">*</span></label>
                        <span class="password-wrap"><input type="password" id="f-password" name="password" required autocomplete="current-password"><button type="button" class="password-toggle" data-password-toggle aria-label="Show password">👁</button></span>
                    </div>
                    <div class="auth-row">
                        <button class="btn btn--dark" type="submit">Log in</button>
                        <label class="checkbox"><input type="checkbox" name="rememberme" value="forever"> Remember me</label>
                    </div>
                    <a class="auth-link" href="<?= e(link_to('/my-account/lost-password/')) ?>">Lost your password?</a>
                </form>
            </div>
            <div>
                <h2 class="auth-title">Register</h2>
                <form class="form cart-panel" method="post" action="<?= e(link_to('/my-account/register')) ?>" novalidate data-register>
                    <?= csrf_field() ?>
                    <?= $this->insert('partials/field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'errors' => $errors]) ?>
                    <p class="field__hint">A link to set a new password will be sent to your email address.</p>
                    <div class="vendor-fields" data-vendor-fields<?= $registerAs === 'vendor' ? '' : ' hidden' ?>>
                        <div class="form__grid form__grid--2">
                            <?= $this->insert('partials/field', ['name' => 'first_name', 'label' => 'First Name', 'required' => true, 'autocomplete' => 'given-name', 'errors' => $errors]) ?>
                            <?= $this->insert('partials/field', ['name' => 'last_name', 'label' => 'Last Name', 'required' => true, 'autocomplete' => 'family-name', 'errors' => $errors]) ?>
                        </div>
                        <?= $this->insert('partials/field', ['name' => 'shopname', 'label' => 'Shop Name', 'required' => true, 'errors' => $errors]) ?>
                        <div class="field<?= isset($errors['shopurl']) ? ' field--error' : '' ?>">
                            <label for="f-shopurl">Shop URL <span class="field__req" aria-hidden="true">*</span></label>
                            <input id="f-shopurl" type="text" name="shopurl" value="<?= e(old('shopurl')) ?>" pattern="[a-z0-9-]+" data-shopurl>
                            <p class="field__hint"><?= e(url('/store/')) ?><strong data-shopurl-preview><?= e(old('shopurl')) ?></strong></p>
                            <?php if (isset($errors['shopurl'])): ?><p class="field__error"><?= e($errors['shopurl']) ?></p><?php endif ?>
                        </div>
                        <?= $this->insert('partials/field', ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'autocomplete' => 'tel', 'errors' => $errors]) ?>
                    </div>
                    <fieldset class="role-choice">
                        <legend class="visually-hidden">Account type</legend>
                        <label class="checkbox"><input type="radio" name="role" value="customer"<?= $registerAs !== 'vendor' ? ' checked' : '' ?>> I am a customer</label>
                        <label class="checkbox"><input type="radio" name="role" value="vendor"<?= $registerAs === 'vendor' ? ' checked' : '' ?>> I am a vendor</label>
                    </fieldset>
                    <div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <p class="field__hint">Your personal data will be used to support your experience throughout this website, to manage access to your account, and for other purposes described in our <a href="<?= e(link_to('/privacy-policy/')) ?>">privacy policy</a>.</p>
                    <button class="btn btn--dark" type="submit">Register</button>
                </form>
            </div>
        </div>
    </div>
</section>
