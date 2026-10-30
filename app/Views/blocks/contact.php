<?php
/** Contact form (role tabs) + contact details, map and socials. */
$site = App\Services\Settings::get('site');
$errors = app()->session()->getFlash('_errors', []);
$sent = app()->session()->getFlash('contact_sent', false);
$role = old('role', 'vendor');
$roles = $b['roles'] ?? ['vendor' => 'Vendor', 'stylist' => 'Stylist', 'customer' => 'Customer'];
?>
<section class="block-contact section-pad section--soft" id="contact-form">
    <div class="container">
        <h2><?= e($b['title']) ?></h2>
        <p class="block-contact__intro"><?= e($b['text']) ?></p>

        <?php if ($sent): ?>
            <div class="notice notice--success" role="status"><?= icon('check') ?> Thank you! Your message has been sent. A member of the NiCa team will get back to you soon.</div>
        <?php endif ?>
        <?php if ($errors): ?>
            <div class="notice notice--error" role="alert">Please check the highlighted fields and try again.</div>
        <?php endif ?>

        <form class="form" method="post" action="<?= e(route('contact.submit')) ?>#contact-form" novalidate>
            <?= csrf_field() ?>
            <fieldset class="role-tabs">
                <legend><?= e($b['roles_label'] ?? 'You are a -') ?></legend>
                <?php foreach ($roles as $value => $label): ?>
                    <label class="role-tabs__option">
                        <input type="radio" name="role" value="<?= e($value) ?>"<?= $role === $value ? ' checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach ?>
            </fieldset>
            <div class="form__grid">
                <?= $this->insert('partials/field', ['name' => 'name', 'label' => 'Full name', 'placeholder' => 'Mr. John', 'required' => true, 'autocomplete' => 'name', 'errors' => $errors]) ?>
                <?= $this->insert('partials/field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'placeholder' => 'you@domain.com', 'required' => true, 'autocomplete' => 'email', 'errors' => $errors]) ?>
                <?= $this->insert('partials/field', ['name' => 'phone', 'label' => 'Phone number', 'type' => 'tel', 'placeholder' => 'Your Contact No.', 'required' => true, 'autocomplete' => 'tel', 'errors' => $errors]) ?>
            </div>
            <?= $this->insert('partials/field', ['name' => 'message', 'label' => 'Your message', 'type' => 'textarea', 'placeholder' => 'Anything you want to add...', 'required' => true, 'errors' => $errors]) ?>
            <?php /* Honeypot: real people never see or fill this. */ ?>
            <div class="form__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <button class="btn btn--dark" type="submit">Send Message <?= icon('arrow-right') ?></button>
        </form>
    </div>
</section>

<section class="block-contact-info section-pad">
    <div class="container block-contact-info__inner">
        <div class="block-contact-info__map">
            <iframe title="Map of <?= e($site['contact']['address']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                    src="https://maps.google.com/maps?q=<?= rawurlencode($b['map_query'] ?? $site['contact']['address']) ?>&amp;t=m&amp;z=14&amp;output=embed&amp;iwloc=near"></iframe>
        </div>
        <div class="block-contact-info__cards">
            <div class="block-contact-info__card">
                <h3>Contact information</h3>
                <ul class="contact-list">
                    <li><?= icon('map-marker') ?><span><?= e($site['contact']['address']) ?></span></li>
                    <li><?= icon('phone') ?><a href="<?= e($site['contact']['phone_href']) ?>"><?= e($site['contact']['phone']) ?></a></li>
                    <li><?= icon('envelope') ?><a href="mailto:<?= e($site['contact']['email']) ?>"><?= e($site['contact']['email']) ?></a></li>
                </ul>
            </div>
            <div class="block-contact-info__card">
                <h3>Follow us:</h3>
                <?= $this->insert('partials/social', ['social' => $site['social']]) ?>
            </div>
        </div>
    </div>
</section>
