<?php $this->layout('emails/layout', ['heading' => 'New stylist application']) ?>
<p><strong><?= e(trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email']) ?></strong> (<?= e($user['email']) ?>) applied to the stylist program with code <strong><?= e($code) ?></strong>.</p>
<p><?= e(trim(($data['salon_name'] ?? '') . ' · ' . $data['city'] . ', ' . $data['state'], ' ·')) ?><?= !empty($data['instagram']) ? ' · Instagram @' . e(ltrim($data['instagram'], '@')) : '' ?></p>
<?php if (!empty($data['about'])): ?><p><?= nl2br(e($data['about'])) ?></p><?php endif ?>
<p><?= $auto ? 'The account was approved automatically.' : 'Review it in the admin panel under Stylists.' ?></p>
