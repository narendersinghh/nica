<?php $this->layout('account/layout') ?>
<p>The following addresses will be used on the checkout page by default.</p>
<div class="address-grid">
    <?php foreach (['billing' => [$billing, 'Billing address'], 'shipping' => [$shipping, 'Shipping address']] as $type => [$a, $title]): ?>
        <div class="address-card">
            <h3><?= e($title) ?></h3>
            <address><?= $a ? $this->insert('account/address', ['a' => $a]) : 'You have not set up this type of address yet.' ?></address>
            <a class="btn btn--sm btn--outline" href="<?= e(link_to('/my-account/edit-address/' . $type . '/')) ?>"><?= $a ? 'Edit' : 'Add' ?> <?= e(strtolower($title)) ?></a>
        </div>
    <?php endforeach ?>
</div>
