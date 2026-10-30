<?php $this->layout('emails/layout', ['heading' => ['approve' => 'Welcome aboard!', 'reject' => 'About your application', 'suspend' => 'Your store is suspended'][$action]]) ?>
<?php if ($action === 'approve'): ?>
<p>Great news: <strong><?= e($vendor['store_name']) ?></strong> is approved and can now sell on <?= e(config('app.name')) ?>.</p>
<p>Next steps: add your logo and banner, set up how you get paid, and publish your products.</p>
<p><a href="<?= e(url('/dashboard/')) ?>" style="color:#C3A36A">Open your vendor dashboard</a></p>
<?php elseif ($action === 'reject'): ?>
<p>Thank you for applying to sell on <?= e(config('app.name')) ?>. We are not able to approve <strong><?= e($vendor['store_name']) ?></strong> at this time.</p>
<?php else: ?>
<p><strong><?= e($vendor['store_name']) ?></strong> has been suspended and its products are hidden from the shop.</p>
<?php endif ?>
<?php if ($reason !== '' && $action !== 'approve'): ?><p><strong>Reason:</strong> <?= e($reason) ?></p><?php endif ?>
<p>Questions? Reply to this email or write to <?= e(\App\Services\Settings::get('site.contact.email')) ?>.</p>
