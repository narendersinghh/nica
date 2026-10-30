<?php $this->layout('emails/layout', ['heading' => $outcome === 'paid' ? 'Your payout is on its way' : 'Payout request declined']) ?>
<?php if ($outcome === 'paid'): ?>
<p>We have sent <strong><?= e(money((int) $req['amount_cents'])) ?></strong> by <?= e(App\Services\Vendor\Earnings::METHODS[$req['method']] ?? $req['method']) ?>.</p>
<p><strong>Reference:</strong> <?= e($text) ?></p>
<?php else: ?>
<p>Your payout request for <strong><?= e(money((int) $req['amount_cents'])) ?></strong> was declined, and the amount is back in your available balance.</p>
<p><strong>Reason:</strong> <?= e($text) ?></p>
<?php endif ?>
<p><a href="<?= e(url(!empty($stylist) ? '/my-account/stylist/' : '/dashboard/withdraw/')) ?>" style="color:#C3A36A">View your earnings</a></p>
