<?php $this->layout('account/simple', ['heading' => 'Lost password']) ?>
<?php if ($sent): ?>
    <div class="notice notice--success" role="status">If an account matches what you entered, a password reset email is on its way. It may take a few minutes. The link expires in 60 minutes.</div>
<?php endif ?>
<p>Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.</p>
<form class="form" method="post" action="<?= e(link_to('/my-account/lost-password/')) ?>" novalidate>
    <?= csrf_field() ?>
    <?= $this->insert('partials/field', ['name' => 'user_login', 'label' => 'Username or email', 'required' => true, 'autocomplete' => 'username', 'errors' => $errors]) ?>
    <button class="btn btn--dark" type="submit">Reset password</button>
</form>
