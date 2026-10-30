<?php /** @var App\Core\Template $this */
$this->layout('layouts/site', ['bodyClass' => 'page-checkout']);
$b = $defaults['billing'];
$s = $defaults['shipping'];
$field = function (string $name, string $label, string $value = '', array $o = []) {
    return $this->insert('partials/field', ['name' => $name, 'label' => $label, 'value' => $value, 'errors' => []] + $o);
};
$stateSelect = function (string $name, string $value) use ($states) {
    $html = '<div class="field"><label for="f-' . e($name) . '">State <span class="field__req" aria-hidden="true">*</span></label><select id="f-' . e($name) . '" name="' . e($name) . '" required autocomplete="address-level1"><option value="">Select an option…</option>';
    foreach ($states as $code => $label) {
        $html .= '<option value="' . e($code) . '"' . ($value === $code ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select></div>';
};
$termsUrl = link_to('/terms-and-conditions/');
?>
<section class="page-banner">
    <div class="container">
        <p class="eyebrow eyebrow--light"><?= icon('card') ?><span>Just one final step</span></p>
        <h1>Checkout</h1>
    </div>
</section>

<section class="section-pad section--soft">
    <div class="container">
        <?php foreach ($notices as $n): ?><div class="notice" role="status"><?= e($n) ?></div><?php endforeach ?>
        <?php if ($error): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif ?>
        <?php if (!$user): ?>
            <div class="notice">Returning customer? <a href="<?= e(link_to('/my-account/?redirect=/checkout/')) ?>">Click here to login</a></div>
        <?php endif ?>
        <details class="coupon-toggle"<?= $couponError ? ' open' : '' ?>>
            <summary>Have a coupon? Click here to enter your code</summary>
            <form method="post" action="<?= e(link_to('/cart/coupon')) ?>" class="coupon-form">
                <?= csrf_field() ?><input type="hidden" name="return" value="checkout">
                <label class="visually-hidden" for="checkout-coupon">Coupon code</label>
                <input id="checkout-coupon" type="text" name="coupon_code" placeholder="Coupon code" maxlength="64" autocomplete="off">
                <button class="btn btn--outline" type="submit">Apply coupon</button>
            </form>
            <?php if ($couponError): ?><p class="field__error" role="alert"><?= e($couponError) ?></p><?php endif ?>
        </details>

        <div class="checkout">
        <form class="checkout__form" id="checkout-form" method="post" action="<?= e(link_to('/checkout/place')) ?>" novalidate
              data-checkout data-total="<?= (int) $summary['totals']['total_cents'] ?>" data-currency="usd">
            <?= csrf_field() ?>
            <input type="hidden" name="expected_total" value="<?= (int) $summary['totals']['total_cents'] ?>">
            <div class="checkout__details cart-panel">
                <h2>Billing Details</h2>
                <div class="form__grid form__grid--2">
                    <?= $field('billing_first_name', 'First Name', (string) ($b['first_name'] ?? ''), ['required' => true, 'autocomplete' => 'given-name']) ?>
                    <?= $field('billing_last_name', 'Last Name', (string) ($b['last_name'] ?? ''), ['required' => true, 'autocomplete' => 'family-name']) ?>
                </div>
                <div class="field"><label>Country / Region</label><p class="field__static">United States (US)</p></div>
                <?= $field('billing_address_1', 'Street Address', (string) ($b['address_1'] ?? ''), ['required' => true, 'placeholder' => 'House number and street name', 'autocomplete' => 'address-line1']) ?>
                <?= $field('billing_address_2', 'Apartment, suite, unit, etc. (optional)', (string) ($b['address_2'] ?? ''), ['autocomplete' => 'address-line2']) ?>
                <div class="form__grid">
                    <?= $field('billing_city', 'Town / City', (string) ($b['city'] ?? ''), ['required' => true, 'autocomplete' => 'address-level2']) ?>
                    <?= $stateSelect('billing_state', (string) ($b['state'] ?? '')) ?>
                    <?= $field('billing_postcode', 'ZIP Code', (string) ($b['postcode'] ?? ''), ['required' => true, 'autocomplete' => 'postal-code']) ?>
                </div>
                <div class="form__grid form__grid--2">
                    <?= $field('billing_phone', 'Phone (optional)', (string) ($b['phone'] ?? ''), ['type' => 'tel', 'autocomplete' => 'tel']) ?>
                    <?= $field('email', 'Email Address', (string) $defaults['email'], ['type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>
                </div>

                <label class="checkbox"><input type="checkbox" name="ship_to_different_address" value="1" data-toggle-shipping<?= $s ? ' checked' : '' ?>> Ship to a different address?</label>
                <div class="checkout__shipping" data-shipping-fields<?= $s ? '' : ' hidden' ?>>
                    <div class="form__grid form__grid--2">
                        <?= $field('shipping_first_name', 'First Name', (string) ($s['first_name'] ?? ''), ['required' => true, 'autocomplete' => 'shipping given-name']) ?>
                        <?= $field('shipping_last_name', 'Last Name', (string) ($s['last_name'] ?? ''), ['required' => true, 'autocomplete' => 'shipping family-name']) ?>
                    </div>
                    <?= $field('shipping_address_1', 'Street Address', (string) ($s['address_1'] ?? ''), ['required' => true, 'autocomplete' => 'shipping address-line1']) ?>
                    <?= $field('shipping_address_2', 'Apartment, suite, unit, etc. (optional)', (string) ($s['address_2'] ?? ''), ['autocomplete' => 'shipping address-line2']) ?>
                    <div class="form__grid">
                        <?= $field('shipping_city', 'Town / City', (string) ($s['city'] ?? ''), ['required' => true, 'autocomplete' => 'shipping address-level2']) ?>
                        <?= $stateSelect('shipping_state', (string) ($s['state'] ?? '')) ?>
                        <?= $field('shipping_postcode', 'ZIP Code', (string) ($s['postcode'] ?? ''), ['required' => true, 'autocomplete' => 'shipping postal-code']) ?>
                    </div>
                </div>
                <?= $field('order_comments', 'Order notes (optional)', '', ['type' => 'textarea', 'rows' => 3, 'placeholder' => 'Notes about your order, e.g. special notes for delivery.']) ?>
                <?php if (App\Services\Referrals\Referrals::enabled()): ?>
                    <?= $field('stylist_code', 'Stylist code (optional)', App\Services\Referrals\Referrals::normalize((string) app()->request()->cookie(App\Services\Referrals\Referrals::COOKIE, '')), ['placeholder' => 'Did a stylist recommend us?', 'autocomplete' => 'off']) ?>
                <?php endif ?>
            </div>

        </form>
        <aside class="checkout__aside cart-panel">
                <h2>Your Order</h2>
                <table class="review-table">
                    <thead><tr><th scope="col">Product</th><th scope="col">Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($summary['packages'] as $pkg): foreach ($pkg['lines'] as $l): ?>
                        <tr><td><?= e($l['name']) ?><?php if ($l['variant_label']): ?><span><?= e($l['variant_label']) ?></span><?php endif ?> <strong>× <?= (int) $l['quantity'] ?></strong></td><td><?= e(money($l['subtotal_cents'])) ?></td></tr>
                    <?php endforeach; endforeach ?>
                    </tbody>
                </table>
            <?= $this->insert('cart/totals', ['summary' => $summary, 'return' => 'checkout']) ?>
            <div class="payment" data-payment>
                <h3>Payment</h3>
                <?php if ($gateway === null): ?>
                    <div class="notice notice--error">Online payments are not configured yet. Please contact us to complete your order.</div>
                <?php elseif ($gateway['gateway'] === 'test'): ?>
                    <div class="notice test-gateway">
                        <strong>Local test payments</strong> — no Stripe keys are set, so no real payment is taken.
                        <label class="checkbox"><input type="radio" name="test_outcome" value="approve" form="checkout-form" checked> Approve the payment</label>
                        <label class="checkbox"><input type="radio" name="test_outcome" value="decline" form="checkout-form"> Decline the payment</label>
                    </div>
                <?php else: ?>
                    <?php if ($gateway['testMode']): ?><p class="payment__test">Stripe test mode: use card 4242 4242 4242 4242, any future date and CVC.</p><?php endif ?>
                    <div id="payment-element" class="payment__element" data-publishable-key="<?= e($gateway['publishableKey']) ?>"></div>
                <?php endif ?>
                <p class="payment__privacy">Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our <a href="<?= e(link_to('/privacy-policy/')) ?>">privacy policy</a>.</p>
                <label class="checkbox"><input type="checkbox" name="terms" value="1" form="checkout-form" required> I have read and agree to the website <a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener">terms and conditions</a> <span class="field__req">*</span></label>
                <p class="field__error" data-error="terms" hidden></p>
                <div class="notice notice--error" data-checkout-error role="alert" hidden></div>
                <button class="btn btn--gold btn--block place-order" type="submit" form="checkout-form" data-place-order<?= $gateway === null ? ' disabled' : '' ?>>Place order · <?= e(money($summary['totals']['total_cents'])) ?></button>
                <p class="payment__secure"><?= icon('check') ?> Payments are encrypted and processed securely by Stripe. We never see or store your card details.</p>
            </div>
        </aside>
        </div>
    </div>
</section>
<?php $this->start('scripts') ?>
<?php if ($gateway && $gateway['gateway'] === 'stripe'): ?><script src="https://js.stripe.com/v3/" nonce="<?= e(csp_nonce()) ?>"></script><?php endif ?>
<script src="<?= e(asset('assets/js/checkout.js')) ?>" defer></script>
<?php $this->stop() ?>
