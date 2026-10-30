<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$method = old('method', (string) ($vendor['payout_method'] ?? 'paypal'));
$d = $details;
$fe = static fn (string $k) => $errors[$k] ?? null;
?>
<div class="grid grid--2">
    <section class="card">
        <div class="card__head"><h2><?= picon('bolt') ?> Automatic payouts with Stripe</h2><?php if ($connected): ?><span class="pill pill--active">Connected</span><?php endif ?></div>
        <?php if ($connected): ?>
            <p>Your share of every new order (after the <?= e(rtrim(rtrim(number_format((float) ($vendor['commission_rate'] ?? App\Services\Settings::get('commerce.commission_rate')), 2), '0'), '.')) ?>% commission) is sent to your Stripe account as soon as the customer pays. Stripe then pays out to your bank on its usual schedule.</p>
            <div class="toolbar">
                <?php if (!$connectSimulated): ?><form method="post" action="<?= e(link_to('/dashboard/settings/payment/stripe/dashboard')) ?>"><?= csrf_field() ?><button class="btn btn--ghost" type="submit"><?= picon('external') ?>Open Stripe dashboard</button></form><?php endif ?>
                <form method="post" action="<?= e(link_to('/dashboard/settings/payment/stripe/disconnect')) ?>" data-confirm="Disconnect Stripe? New orders will be paid out manually instead."><?= csrf_field() ?><button class="link-btn link-btn--danger" type="submit">Disconnect</button></form>
            </div>
        <?php elseif (!empty($vendor['stripe_account_id'])): ?>
            <div class="alert alert--warn"><?= picon('alert') ?><div>Your Stripe setup is not finished yet. Stripe needs a few more details before payments can be split.</div></div>
            <form method="post" action="<?= e(link_to('/dashboard/settings/payment/stripe')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Continue Stripe setup</button></form>
        <?php elseif ($connectAvailable): ?>
            <p>Connect a Stripe account and get paid automatically on every order, with no payout requests and no minimum balance.</p>
            <form method="post" action="<?= e(link_to('/dashboard/settings/payment/stripe')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= picon('bolt') ?>Connect with Stripe</button></form>
            <?php if ($connectSimulated): ?><p class="hint muted small">Local test mode: no Stripe keys are set, so this connects a simulated account.</p><?php endif ?>
        <?php else: ?>
            <p class="muted">Automatic Stripe payouts are not switched on for the marketplace yet. Use PayPal or bank transfer below.</p>
        <?php endif ?>
    </section>

    <section class="card">
        <h2><?= picon('wallet') ?> Manual payouts</h2>
        <p class="small muted">Used for any balance not paid through Stripe. Request a payout from <a href="<?= e(link_to('/dashboard/withdraw/')) ?>">Earnings</a> once you have at least <?= e(money($threshold)) ?> available. Details are stored encrypted.</p>
        <form method="post" action="<?= e(link_to('/dashboard/settings/payment/')) ?>" data-method-form>
            <?= csrf_field() ?>
            <div class="fld">
                <span class="fld-label">Pay me by</span>
                <div class="segmented">
                    <label><input type="radio" name="method" value="paypal" data-method-toggle<?= $method === 'paypal' ? ' checked' : '' ?>>PayPal</label>
                    <label><input type="radio" name="method" value="bank" data-method-toggle<?= $method === 'bank' ? ' checked' : '' ?>>Bank transfer</label>
                </div>
            </div>
            <div data-method-panel="paypal"<?= $method === 'paypal' ? '' : ' hidden' ?>>
                <div class="fld<?= $fe('paypal_email') ? ' fld--error' : '' ?>"><label for="f-pp">PayPal email</label><input type="email" id="f-pp" name="paypal_email" value="<?= e(old('paypal_email', $vendor['payout_method'] === 'paypal' ? (string) ($d['email'] ?? '') : '')) ?>" autocomplete="email"><?php if ($fe('paypal_email')): ?><p class="err">PayPal email <?= e($fe('paypal_email')) ?></p><?php endif ?></div>
            </div>
            <div data-method-panel="bank"<?= $method === 'bank' ? '' : ' hidden' ?>>
                <div class="form-grid">
                    <div class="fld full<?= $fe('account_name') ? ' fld--error' : '' ?>"><label for="f-an">Account holder name</label><input type="text" id="f-an" name="account_name" value="<?= e(old('account_name', (string) ($d['account_name'] ?? ''))) ?>" maxlength="100"></div>
                    <div class="fld full<?= $fe('bank_name') ? ' fld--error' : '' ?>"><label for="f-bn">Bank name</label><input type="text" id="f-bn" name="bank_name" value="<?= e(old('bank_name', (string) ($d['bank_name'] ?? ''))) ?>" maxlength="100"></div>
                    <div class="fld<?= $fe('routing_number') ? ' fld--error' : '' ?>"><label for="f-rn">Routing number</label><input type="text" id="f-rn" name="routing_number" inputmode="numeric" maxlength="9" value="<?= e(old('routing_number', (string) ($d['routing_number'] ?? ''))) ?>" autocomplete="off"><?php if ($fe('routing_number')): ?><p class="err">Routing numbers have 9 digits.</p><?php endif ?></div>
                    <div class="fld<?= $fe('account_number') ? ' fld--error' : '' ?>"><label for="f-acc">Account number</label><input type="text" id="f-acc" name="account_number" inputmode="numeric" maxlength="17" value="" placeholder="<?= !empty($d['account_number']) ? '••••' . e(substr((string) $d['account_number'], -4)) : '' ?>" autocomplete="off"><?php if ($fe('account_number')): ?><p class="err">Enter 4 to 17 digits.</p><?php endif ?></div>
                    <div class="fld"><label for="f-at">Account type</label><select id="f-at" name="account_type"><option value="checking"<?= ($d['account_type'] ?? '') !== 'savings' ? ' selected' : '' ?>>Checking</option><option value="savings"<?= ($d['account_type'] ?? '') === 'savings' ? ' selected' : '' ?>>Savings</option></select></div>
                </div>
                <p class="hint muted small">For your security the full account number is never shown again. Re-enter it to change bank details.</p>
            </div>
            <button class="btn" type="submit">Save payout details</button>
        </form>
    </section>
</div>
