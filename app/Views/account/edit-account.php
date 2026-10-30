<?php $this->layout('account/layout') ?>
<form class="form" method="post" action="<?= e(link_to('/my-account/edit-account/')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form__grid form__grid--2">
        <?= $this->insert('partials/field', ['name' => 'first_name', 'label' => 'First name', 'value' => $user['first_name'], 'required' => true, 'autocomplete' => 'given-name', 'errors' => $errors]) ?>
        <?= $this->insert('partials/field', ['name' => 'last_name', 'label' => 'Last name', 'value' => $user['last_name'], 'required' => true, 'autocomplete' => 'family-name', 'errors' => $errors]) ?>
    </div>
    <?= $this->insert('partials/field', ['name' => 'display_name', 'label' => 'Display name', 'value' => $user['display_name'], 'required' => true, 'errors' => $errors]) ?>
    <p class="field__hint">This will be how your name will be displayed in the account section and in reviews.</p>
    <?= $this->insert('partials/field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'value' => $user['email'], 'required' => true, 'autocomplete' => 'email', 'errors' => $errors]) ?>
    <fieldset class="password-change">
        <legend>Password change</legend>
        <?php if ($user['password_hash']): ?>
            <?= $this->insert('partials/field', ['name' => 'password_current', 'label' => 'Current password (needed to change email or password)', 'type' => 'password', 'autocomplete' => 'current-password', 'errors' => $errors]) ?>
        <?php else: ?>
            <p class="field__hint">You haven't set a password yet. Use the link we emailed you, or set one here.</p>
        <?php endif ?>
        <?= $this->insert('partials/field', ['name' => 'password', 'label' => 'New password (leave blank to leave unchanged)', 'type' => 'password', 'autocomplete' => 'new-password', 'errors' => $errors]) ?>
        <?= $this->insert('partials/field', ['name' => 'password_confirmation', 'label' => 'Confirm new password', 'type' => 'password', 'autocomplete' => 'new-password', 'errors' => $errors]) ?>
        <p class="field__hint">At least <?= (int) config('security.password.min_length', 10) ?> characters. A passphrase of a few words works well.</p>
    </fieldset>
    <button class="btn btn--dark" type="submit">Save changes</button>
</form>
