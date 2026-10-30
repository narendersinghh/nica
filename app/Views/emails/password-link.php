<?php $this->layout('emails/layout', ['heading' => $welcome ? 'Welcome to ' . config('app.name') : 'Reset your password']) ?>
<p>Hi <?= e($user['first_name'] ?: $user['display_name']) ?>,</p>
<?php if ($welcome): ?>
    <p>Thanks for creating an account. Your username is <strong><?= e($user['username'] ?? $user['email']) ?></strong>. Set your password to finish setting up your account:</p>
<?php else: ?>
    <p>Someone asked to reset the password for your account. If this was you, use the button below. The link expires in <?= (int) $minutes ?> minutes.</p>
<?php endif ?>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#2C211C;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none"><?= $welcome ? 'Set your password' : 'Reset password' ?></a></p>
<p style="color:#777;font-size:13px">If you didn't ask for this, you can ignore this email; your account stays as it is.</p>
