<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\VendorsController;
$this->layout('layouts/panel');
$fe = static fn (string $k) => $errors[$k] ?? null;
$num = static fn ($v) => $v === null ? '' : rtrim(rtrim((string) $v, '0'), '.');
$base = '/admin/vendors/' . $v['id'] . '/';
?>
<?php $this->start('actions') ?>
<a class="btn btn--ghost" href="<?= e(link_to('/store/' . $v['slug'] . '/')) ?>" target="_blank" rel="noopener"><?= picon('eye') ?>Storefront</a>
<?php if ($canEdit && $owner && $v['status'] !== 'disabled'): ?>
<form method="post" action="<?= e(link_to($base . 'impersonate')) ?>" data-confirm="Open the vendor dashboard as this vendor? This is recorded in the audit log."><?= csrf_field() ?><button class="btn btn--ghost" type="submit"><?= picon('users') ?>Log in as vendor</button></form>
<?php endif ?>
<?php $this->stop() ?>

<p><span class="pill pill--<?= e($v['status']) ?>"><?= e(VendorsController::STATUSES[$v['status']]) ?></span>
<?php if ((int) $v['is_premier_partner']): ?> <span class="pill pill--pending">Premier Partner #<?= (int) $v['premier_number'] ?></span><?php endif ?>
<?php if ((int) $v['vacation_mode']): ?> <span class="pill">Vacation mode</span><?php endif ?></p>

<div class="stats">
    <div class="stat"><div class="stat__label">Sales (all time)</div><div class="stat__value"><?= e(money((int) $stats['sales'])) ?></div><div class="stat__meta"><?= (int) $stats['orders'] ?> orders</div></div>
    <div class="stat"><div class="stat__label">Commission earned</div><div class="stat__value"><?= e(money((int) $stats['commission'])) ?></div></div>
    <div class="stat"><div class="stat__label">Available balance</div><div class="stat__value"><?= e(money($balance['withdrawable'])) ?></div><div class="stat__meta"><?= e(money($balance['pending'])) ?> pending</div></div>
    <div class="stat"><div class="stat__label">Products</div><div class="stat__value"><?= $productCount ?></div><div class="stat__meta"><a href="<?= e(link_to('/admin/products/?vendor=' . $v['id'])) ?>">View products</a></div></div>
</div>

<div class="grid grid--main">
    <div>
        <section class="card">
            <h2>Marketplace terms</h2>
            <form method="post" action="<?= e(link_to($base)) ?>">
                <?= csrf_field() ?>
                <fieldset class="plain"<?= $canEdit ? '' : ' disabled' ?>>
                <div class="form-grid">
                    <div class="fld full<?= $fe('store_name') ? ' fld--error' : '' ?>"><label for="f-sn">Store name</label><input type="text" id="f-sn" name="store_name" value="<?= e(old('store_name', $v['store_name'])) ?>" maxlength="100"></div>
                    <div class="fld<?= $fe('commission_rate') ? ' fld--error' : '' ?>"><label for="f-cr">Commission (%)</label><input type="number" step="0.01" min="0" max="100" id="f-cr" name="commission_rate" value="<?= e(old('commission_rate', $num($v['commission_rate']))) ?>" placeholder="Default <?= e($num($defaultCommission)) ?>"><p class="hint">Empty = marketplace default. Applies to new orders.</p></div>
                    <div class="fld<?= $fe('payout_threshold') ? ' fld--error' : '' ?>"><label for="f-pt">Minimum payout ($)</label><input type="number" step="0.01" min="0" id="f-pt" name="payout_threshold" value="<?= e(old('payout_threshold', $v['payout_threshold_cents'] !== null ? number_format($v['payout_threshold_cents'] / 100, 2, '.', '') : '')) ?>" placeholder="Default <?= e(money($defaultThreshold)) ?>"></div>
                    <div class="fld<?= $fe('nica_score') ? ' fld--error' : '' ?>"><label for="f-ns">NiCa Score (0–10)</label><input type="number" step="0.1" min="0" max="10" id="f-ns" name="nica_score" value="<?= e(old('nica_score', $num($v['nica_score']))) ?>"></div>
                    <div class="fld<?= $fe('premier_number') ? ' fld--error' : '' ?>"><label for="f-pn">Premier Partner number</label><input type="number" min="1" id="f-pn" name="premier_number" value="<?= e(old('premier_number', (string) $v['premier_number'])) ?>" placeholder="Next free number"><?php if ($fe('premier_number')): ?><p class="err">This number <?= e($fe('premier_number')) ?></p><?php endif ?></div>
                    <div class="fld full">
                        <label class="check"><input type="checkbox" name="is_premier_partner" value="1"<?= (int) $v['is_premier_partner'] ? ' checked' : '' ?>>Premier Partner badge</label>
                        <label class="check"><input type="checkbox" name="is_featured" value="1"<?= (int) $v['is_featured'] ? ' checked' : '' ?>>Featured store</label>
                    </div>
                </div>
                <?php if ($canEdit): ?><p><button class="btn" type="submit">Save</button></p><?php endif ?>
                </fieldset>
            </form>
        </section>
        <section class="card">
            <h2>Store profile</h2>
            <dl class="kv kv--left">
                <dt>Store URL</dt><dd><a href="<?= e(link_to('/store/' . $v['slug'] . '/')) ?>" target="_blank" rel="noopener">/store/<?= e($v['slug']) ?>/</a></dd>
                <dt>Tagline</dt><dd><?= e((string) $v['tagline']) ?: '—' ?></dd>
                <dt>Public email</dt><dd><?= e((string) $v['public_email']) ?: '—' ?></dd>
                <dt>Phone</dt><dd><?= e((string) $v['phone']) ?: '—' ?></dd>
                <dt>Payouts</dt><dd><?= $connected ? 'Stripe Connect (automatic)' : e($payoutMethod) ?></dd>
                <dt>Approved</dt><dd><?= $v['approved_at'] ? e(gmdate('M j, Y', strtotime($v['approved_at']))) : '—' ?></dd>
            </dl>
        </section>
    </div>
    <div>
        <?php if ($canEdit): ?>
        <section class="card">
            <h2>Status</h2>
            <?php if ($v['status'] === 'pending'): ?>
                <form method="post" action="<?= e(link_to($base . 'approve')) ?>"><?= csrf_field() ?><button class="btn btn--block" type="submit"><?= picon('check') ?>Approve store</button></form>
                <p class="hint muted small">The vendor is emailed. The first 50 stores get a numbered Premier Partner badge.</p>
            <?php elseif ($v['status'] === 'approved'): ?>
                <form method="post" action="<?= e(link_to($base . 'suspend')) ?>" data-confirm="Suspend this store? Its products are hidden at once.">
                    <?= csrf_field() ?>
                    <div class="fld"><label for="f-sr">Reason (sent to the vendor)</label><textarea id="f-sr" name="reason" rows="2" maxlength="500"></textarea></div>
                    <button class="btn btn--danger btn--block" type="submit">Suspend store</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(link_to($base . 'reactivate')) ?>"><?= csrf_field() ?><button class="btn btn--block" type="submit">Reactivate store</button></form>
            <?php endif ?>
            <?php if ($v['status'] === 'pending'): ?>
                <hr class="sep">
                <form method="post" action="<?= e(link_to($base . 'reject')) ?>" data-confirm="Reject this application?">
                    <?= csrf_field() ?>
                    <div class="fld"><label for="f-rr">Reason (sent to the applicant)</label><textarea id="f-rr" name="reason" rows="2" maxlength="500"></textarea></div>
                    <button class="btn btn--danger btn--block" type="submit">Reject application</button>
                </form>
            <?php endif ?>
        </section>
        <?php endif ?>
        <section class="card">
            <h2>Owner</h2>
            <?php if ($owner): ?>
                <p><a href="<?= e(link_to('/admin/customers/' . $owner['id'] . '/')) ?>"><strong><?= e(trim($owner['first_name'] . ' ' . $owner['last_name']) ?: $owner['email']) ?></strong></a><br><?= e($owner['email']) ?><?= $owner['phone'] ? '<br>' . e($owner['phone']) : '' ?></p>
                <p class="muted small">Joined <?= e(gmdate('M j, Y', strtotime($owner['created_at']))) ?> · Last login <?= $owner['last_login_at'] ? e(gmdate('M j, Y', strtotime($owner['last_login_at']))) : 'never' ?></p>
            <?php else: ?><p class="muted">No owner account.</p><?php endif ?>
            <p><a class="btn btn--ghost btn--sm" href="<?= e(link_to('/admin/payouts/ledger/' . $v['id'] . '/')) ?>"><?= picon('wallet') ?>Ledger &amp; adjustments</a></p>
        </section>
    </div>
</div>
