<?php $this->layout('emails/layout', ['heading' => 'New vendor application']) ?>
<p><strong><?= e($shop) ?></strong> (<?= e(url('/store/' . $slug . '/')) ?>) applied to sell on <?= e(config('app.name')) ?>.</p>
<p>Contact: <?= e(trim($user['first_name'] . ' ' . $user['last_name'])) ?> · <?= e($user['email']) ?> · <?= e((string) $user['phone']) ?></p>
<p>Review and approve the store in the admin panel.</p>
