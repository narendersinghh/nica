<?php $this->layout('account/layout') ?>
<h2 class="account-subtitle">Two-factor authentication</h2>
<?php if ($recoveryCodes): ?>
    <div class="notice notice--success" role="status">
        <div><strong>Two-factor authentication is on.</strong> Save these one-time recovery codes somewhere safe. Each can be used once if you lose your phone. They won't be shown again.
        <ul class="recovery-codes"><?php foreach ($recoveryCodes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach ?></ul></div>
    </div>
<?php endif ?>
<?php if ($enabled): ?>
    <p>Two-factor authentication is <strong>on</strong>. You'll be asked for a code from your authenticator app when you log in. Recovery codes left: <?= (int) $remaining ?>.</p>
    <?php if (!app()->auth()->hasRole('admin')): ?>
    <form class="form" method="post" action="<?= e(link_to('/my-account/security/disable')) ?>">
        <?= csrf_field() ?>
        <?= $this->insert('partials/field', ['name' => 'password', 'label' => 'Confirm your password to turn it off', 'type' => 'password', 'autocomplete' => 'current-password', 'errors' => $errors]) ?>
        <button class="btn btn--outline" type="submit">Turn off two-factor authentication</button>
    </form>
    <?php endif ?>
<?php else: ?>
    <p>Add a second step to your login with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…).</p>
    <ol class="steps-list">
        <li>In your authenticator app, add an account and enter this key: <code class="secret"><?= e($setup['secret']) ?></code><br>On your phone? <a href="<?= e($setup['url']) ?>">Open it in your authenticator app</a>.</li>
        <li>Enter the 6-digit code the app shows:</li>
    </ol>
    <form class="form" method="post" action="<?= e(link_to('/my-account/security/enable')) ?>">
        <?= csrf_field() ?>
        <?= $this->insert('partials/field', ['name' => 'code', 'label' => 'Authentication code', 'autocomplete' => 'one-time-code', 'placeholder' => '123456', 'errors' => $errors]) ?>
        <button class="btn btn--dark" type="submit">Turn on two-factor authentication</button>
    </form>
<?php endif ?>
