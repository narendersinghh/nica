<?php $this->layout('account/simple', ['heading' => 'Two-factor authentication']) ?>
<p>Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<?php if ($error): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif ?>
<form class="form" method="post" action="<?= e(link_to('/my-account/two-factor/')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="f-code">Code</label><input id="f-code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required maxlength="20"></div>
    <button class="btn btn--dark" type="submit">Verify</button>
</form>
