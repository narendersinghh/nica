<?php $this->layout('account/layout') ?>
<p>Hello <strong><?= e($user['display_name'] ?: $user['first_name']) ?></strong> (not <?= e($user['display_name'] ?: $user['email']) ?>? <a href="<?= e(link_to('/my-account/customer-logout/')) ?>">Log out</a>)</p>
<p>From your account dashboard you can view your <a href="<?= e(link_to('/my-account/orders/')) ?>">recent orders</a>, manage your <a href="<?= e(link_to('/my-account/edit-address/')) ?>">shipping and billing addresses</a>, and <a href="<?= e(link_to('/my-account/edit-account/')) ?>">edit your password and account details</a>.</p>
<?php if ($vendor): ?>
    <div class="notice<?= $vendor['status'] === 'approved' ? ' notice--success' : '' ?>">
        <?php if ($vendor['status'] === 'approved'): ?>Your store <strong><?= e($vendor['store_name']) ?></strong> is live. <a href="<?= e(link_to('/dashboard/')) ?>">Open the vendor dashboard</a>
        <?php else: ?>Your vendor application for <strong><?= e($vendor['store_name']) ?></strong> is being reviewed. We'll email you when it's approved.<?php endif ?>
    </div>
<?php endif ?>
<div class="account-cards">
    <a class="account-card" href="<?= e(link_to('/my-account/orders/')) ?>"><?= icon('bag') ?><span>Orders</span></a>
    <?php if (App\Services\Points::enabled()): ?><a class="account-card" href="<?= e(link_to('/my-account/points/')) ?>"><?= icon('star') ?><span><?= (int) $user['points_balance'] ?> points</span></a><?php endif ?>
    <a class="account-card" href="<?= e(link_to('/find-match/')) ?>"><?= icon('magic') ?><span>Find my match</span></a>
    <a class="account-card" href="<?= e(link_to('/my-account/security/')) ?>"><?= icon('shield') ?><span><?= $user['two_factor_enabled_at'] ? '2FA on' : 'Turn on 2FA' ?></span></a>
</div>
<?php if ($recent): ?>
    <h2 class="account-subtitle">Recent orders</h2>
    <?= $this->insert('account/order-table', ['orders' => $recent]) ?>
<?php endif ?>
