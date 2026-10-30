<?php $this->layout('emails/layout', ['heading' => $approved ? 'You are in!' : 'About your stylist application']) ?>
<?php if ($approved): ?>
<p>Your stylist account is approved. Your referral code is <strong><?= e($stylist['code']) ?></strong>.</p>
<p>Share this link with your clients: <a href="<?= e(url('/?ref=' . $stylist['code'])) ?>" style="color:#C3A36A"><?= e(url('/?ref=' . $stylist['code'])) ?></a></p>
<p><a href="<?= e(url('/my-account/stylist/')) ?>" style="color:#C3A36A">Open your stylist dashboard</a></p>
<?php else: ?>
<p>Your stylist account is <?= e($status) ?>.<?= $note !== '' ? ' ' . e($note) : '' ?></p>
<p>Questions? Write to <?= e(App\Services\Settings::get('site.contact.email')) ?>.</p>
<?php endif ?>
