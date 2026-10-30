<?php $this->layout('account/layout'); $f = fn ($k) => (string) ($address[$k] ?? ''); ?>
<h2 class="account-subtitle"><?= $type === 'billing' ? 'Billing address' : 'Shipping address' ?></h2>
<form class="form" method="post" action="<?= e(link_to('/my-account/edit-address/' . $type . '/')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form__grid form__grid--2">
        <?= $this->insert('partials/field', ['name' => 'first_name', 'label' => 'First name', 'value' => $f('first_name'), 'required' => true, 'errors' => $errors]) ?>
        <?= $this->insert('partials/field', ['name' => 'last_name', 'label' => 'Last name', 'value' => $f('last_name'), 'required' => true, 'errors' => $errors]) ?>
    </div>
    <div class="field"><label>Country / Region</label><p class="field__static">United States (US)</p></div>
    <?= $this->insert('partials/field', ['name' => 'address_1', 'label' => 'Street address', 'value' => $f('address_1'), 'required' => true, 'autocomplete' => 'address-line1', 'errors' => $errors]) ?>
    <?= $this->insert('partials/field', ['name' => 'address_2', 'label' => 'Apartment, suite, unit, etc. (optional)', 'value' => $f('address_2'), 'autocomplete' => 'address-line2', 'errors' => $errors]) ?>
    <div class="form__grid">
        <?= $this->insert('partials/field', ['name' => 'city', 'label' => 'Town / City', 'value' => $f('city'), 'required' => true, 'errors' => $errors]) ?>
        <div class="field<?= isset($errors['state']) ? ' field--error' : '' ?>"><label for="f-state">State <span class="field__req">*</span></label>
            <select id="f-state" name="state" required><option value="">Select an option…</option><?php foreach ($states as $code => $label): ?><option value="<?= e($code) ?>"<?= old('state', $f('state')) === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
            <?php if (isset($errors['state'])): ?><p class="field__error"><?= e($errors['state']) ?></p><?php endif ?></div>
        <?= $this->insert('partials/field', ['name' => 'postcode', 'label' => 'ZIP Code', 'value' => $f('postcode'), 'required' => true, 'errors' => $errors]) ?>
    </div>
    <?php if ($type === 'billing'): ?><?= $this->insert('partials/field', ['name' => 'phone', 'label' => 'Phone (optional)', 'type' => 'tel', 'value' => $f('phone'), 'errors' => $errors]) ?><?php endif ?>
    <button class="btn btn--dark" type="submit">Save address</button>
</form>
