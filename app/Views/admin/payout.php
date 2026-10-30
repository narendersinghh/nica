<?php
/** @var App\Core\Template $this */
use App\Services\Vendor\Earnings;
$this->layout('layouts/panel');
$open = in_array($req['status'], ['pending', 'approved'], true);
$labels = ['email' => 'PayPal email', 'account_name' => 'Account holder', 'bank_name' => 'Bank', 'routing_number' => 'Routing number', 'account_number' => 'Account number', 'account_type' => 'Account type'];
?>
<div class="grid grid--main">
    <div>
        <section class="card">
            <div class="card__head"><h2><?= e(money((int) $req['amount_cents'])) ?> to <?= e($v['store_name']) ?></h2><span class="pill pill--<?= e($req['status'] === 'approved' ? 'approved-req' : $req['status']) ?>"><?= e(ucfirst($req['status'])) ?></span></div>
            <dl class="kv kv--left">
                <dt>Requested</dt><dd><?= e(gmdate('M j, Y g:ia', strtotime($req['created_at']))) ?> UTC</dd>
                <dt>Method</dt><dd><?= e(Earnings::METHODS[$req['method']] ?? $req['method']) ?></dd>
                <?php foreach ($details ?? [] as $k => $val): ?><dt><?= e($labels[$k] ?? $k) ?></dt><dd><strong class="copyable"><?= e((string) $val) ?></strong></dd><?php endforeach ?>
                <?php if (!$details): ?><dt>Details</dt><dd class="muted">Not available (request imported from WordPress). Check the vendor's payout settings.</dd><?php endif ?>
                <?php if ($req['vendor_note']): ?><dt>Vendor note</dt><dd><?= e($req['vendor_note']) ?></dd><?php endif ?>
                <?php if ($req['reference']): ?><dt>Reference</dt><dd><?= e($req['reference']) ?></dd><?php endif ?>
                <?php if ($req['admin_note']): ?><dt>Admin note</dt><dd><?= e($req['admin_note']) ?></dd><?php endif ?>
            </dl>
            <p class="hint muted small"><?= picon('shield') ?> Viewing these details is recorded in the audit log.</p>
        </section>
        <?php if ($open): ?>
        <section class="card">
            <h2>Send the money, then confirm</h2>
            <form method="post" action="<?= e(link_to($base . 'pay')) ?>" data-confirm="Confirm that <?= e(money((int) $req['amount_cents'])) ?> has been sent?">
                <?= csrf_field() ?>
                <div class="fld"><label for="f-ref">Transaction reference</label><input type="text" id="f-ref" name="reference" maxlength="100" required placeholder="PayPal transaction ID or bank confirmation number"></div>
                <button class="btn" type="submit"><?= picon('check') ?>Mark as paid</button>
            </form>
        </section>
        <?php endif ?>
    </div>
    <div>
        <section class="card">
            <h2><?= $owner === 'stylist' ? 'Stylist' : 'Vendor' ?> balance</h2>
            <dl class="kv"><dt>Available</dt><dd class="num"><?= e(money($balance['available'])) ?></dd><dt>Pending</dt><dd class="num"><?= e(money($balance['pending'])) ?></dd><dt>Paid out</dt><dd class="num"><?= e(money($balance['paid_out'])) ?></dd></dl>
            <?php if ($open && $balance['available'] < (int) $req['amount_cents']): ?><div class="alert alert--error small"><div>The available balance is lower than this request (a refund may have happened since).</div></div><?php endif ?>
            <p><a class="small" href="<?= e(link_to($ledgerUrl)) ?>">Open ledger</a></p>
        </section>
        <?php if ($open): ?>
        <section class="card">
            <h2>Decline</h2>
            <form method="post" action="<?= e(link_to($base . 'reject')) ?>">
                <?= csrf_field() ?>
                <div class="fld"><label for="f-rn">Reason (sent to the vendor)</label><textarea id="f-rn" name="note" rows="3" maxlength="500" required></textarea></div>
                <button class="btn btn--danger btn--sm" type="submit">Decline request</button>
            </form>
        </section>
        <?php endif ?>
    </div>
</div>
