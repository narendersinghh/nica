<?php $this->layout('account/simple', ['heading' => $welcome ? 'Set your password' : 'Reset password']) ?>
<?php if (!$valid): ?>
    <div class="notice notice--error">This link is invalid or has expired.</div>
    <a class="btn btn--dark" href="<?= e(link_to('/my-account/lost-password/')) ?>">Request a new link</a>
<?php else: ?>
    <p>Enter a new password below. Use at least <?= (int) config('security.password.min_length', 10) ?> characters.</p>
    <form class="form" method="post" action="<?= e(link_to('/my-account/reset-password/')) ?>" novalidate>
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <?= $this->insert('partials/field', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'errors' => $errors]) ?>
        <?= $this->insert('partials/field', ['name' => 'password_confirmation', 'label' => 'Re-enter new password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'errors' => $errors]) ?>
        <button class="btn btn--dark" type="submit">Save password</button>
    </form>
<?php endif ?>
