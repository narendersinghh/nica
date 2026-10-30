<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\CustomersController;
$this->layout('layouts/panel');
$base = '/admin/customers/' . $u['id'] . '/';
$b = json_decode((string) $u['billing_address'], true) ?: [];
?>
<p><?php foreach ($roles as $r): ?><span class="pill"><?= e(CustomersController::ROLES[$r] ?? $r) ?></span> <?php endforeach ?><?= $u['status'] !== 'active' ? '<span class="pill pill--cancelled">' . e(ucfirst($u['status'])) . '</span>' : '' ?><?= $u['two_factor_enabled_at'] ? ' <span class="pill pill--published">2FA on</span>' : '' ?></p>
<div class="grid grid--main">
    <div>
        <section class="card card--flush">
            <div class="card__head"><h2>Orders</h2></div>
            <?php if (!$orders): ?><div class="empty"><?= picon('receipt') ?>No orders.</div><?php else: ?>
            <div class="table-wrap"><table class="table table--stack"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="right">Total</th></tr></thead><tbody>
            <?php foreach ($orders as $o): ?><tr><td class="prod-col"><a href="<?= e(link_to('/admin/orders/' . $o['id'] . '/')) ?>">#<?= e($o['number']) ?></a></td><td data-label="Date"><?= e(gmdate('M j, Y', strtotime($o['created_at']))) ?></td><td data-label="Status"><?= $this->insert('admin/_order-status', ['status' => $o['status']]) ?></td><td data-label="Total" class="right num"><?= e(money((int) $o['total_cents'])) ?></td></tr><?php endforeach ?>
            </tbody></table></div>
            <?php endif ?>
        </section>
        <section class="card">
            <div class="card__head"><h2>Points</h2><strong class="num"><?= (int) $u['points_balance'] ?> points</strong></div>
            <?php if ($canPoints): ?>
            <form class="toolbar" method="post" action="<?= e(link_to($base . 'points')) ?>">
                <?= csrf_field() ?>
                <label class="sr-only" for="f-pts">Points</label><input class="input input--sm" type="number" id="f-pts" name="points" placeholder="+50 or -20" required>
                <label class="sr-only" for="f-pn">Note</label><input class="input" type="text" id="f-pn" name="note" maxlength="200" placeholder="Reason" required>
                <button class="btn btn--ghost btn--sm" type="submit">Adjust</button>
            </form>
            <?php endif ?>
            <ul class="list-rows">
                <?php foreach ($points as $p): ?><li><span><?= e(ucfirst($p['reason'])) ?><?= $p['note'] ? ' · <span class="muted">' . e($p['note']) . '</span>' : '' ?><br><span class="muted small"><?= e(gmdate('M j, Y', strtotime($p['created_at']))) ?></span></span><strong class="num<?= (int) $p['points'] < 0 ? ' trend--down' : '' ?>"><?= (int) $p['points'] > 0 ? '+' : '' ?><?= (int) $p['points'] ?></strong></li><?php endforeach ?>
            </ul>
        </section>
    </div>
    <div>
        <section class="card">
            <h2>Contact</h2>
            <dl class="kv kv--left">
                <dt>Email</dt><dd><a href="mailto:<?= e($u['email']) ?>"><?= e($u['email']) ?></a></dd>
                <dt>Username</dt><dd><?= e((string) $u['username']) ?></dd>
                <dt>Phone</dt><dd><?= e((string) ($u['phone'] ?: ($b['phone'] ?? ''))) ?: '—' ?></dd>
                <dt>Joined</dt><dd><?= e(gmdate('M j, Y', strtotime($u['created_at']))) ?></dd>
                <dt>Last login</dt><dd><?= $u['last_login_at'] ? e(gmdate('M j, Y g:ia', strtotime($u['last_login_at']))) : 'Never' ?></dd>
                <?php if ($store): ?><dt>Store</dt><dd><a href="<?= e(link_to('/admin/vendors/' . $store['id'] . '/')) ?>"><?= e($store['store_name']) ?></a></dd><?php endif ?>
            </dl>
        </section>
        <section class="card">
            <h2>Account</h2>
            <form method="post" action="<?= e(link_to($base . 'reset-password')) ?>"><?= csrf_field() ?><p><button class="btn btn--ghost btn--block" type="submit">Send password reset link</button></p></form>
            <?php if (!$isSelf): ?>
                <?php if ($u['status'] === 'active'): ?>
                    <form method="post" action="<?= e(link_to($base . 'suspend')) ?>" data-confirm="Suspend this account? They are signed out and cannot log in."><?= csrf_field() ?><button class="btn btn--danger btn--block" type="submit">Suspend account</button></form>
                <?php else: ?>
                    <form method="post" action="<?= e(link_to($base . 'activate')) ?>"><?= csrf_field() ?><button class="btn btn--block" type="submit">Reactivate account</button></form>
                <?php endif ?>
            <?php endif ?>
        </section>
        <?php if ($canRoles): ?>
        <section class="card">
            <h2>Roles</h2>
            <form method="post" action="<?= e(link_to($base . 'roles')) ?>" data-confirm="Change this person's roles?">
                <?= csrf_field() ?>
                <?php foreach (CustomersController::ROLES as $k => $l): ?><label class="check"><input type="checkbox" name="roles[]" value="<?= e($k) ?>"<?= in_array($k, $roles, true) ? ' checked' : '' ?>><?= e($l) ?></label><?php endforeach ?>
                <p class="hint muted small">Staff can handle orders, products, reviews and customers. Administrators can do everything, including finance and settings.</p>
                <button class="btn btn--ghost btn--sm" type="submit">Save roles</button>
            </form>
        </section>
        <?php endif ?>
    </div>
</div>
