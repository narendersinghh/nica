<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Env;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Validator;
use App\Services\Orders\OrderService;
use App\Services\Payments\Payments;
use App\Services\Payments\StripeClient;
use App\Services\Payments\StripeConnect;
use App\Services\Referrals\Referrals;
use App\Services\Payments\StripeException;
use App\Services\Payments\TestGateway;
use App\Services\Seo;

/**
 * Checkout with the Stripe Payment Element ("deferred intent" flow):
 * 1. the page shows the form + Payment Element sized to the cart total;
 * 2. "Place order" posts here: we re-price the cart, create the pending order
 *    and a PaymentIntent for exactly its total, and return the client secret;
 * 3. the browser confirms the payment with Stripe and returns to complete();
 * 4. complete() (and the webhook) verify the intent with Stripe before marking paid.
 */
final class CheckoutController extends Controller
{
    public function show(Request $request): Response
    {
        $cart = $this->app->cart();
        $summary = $cart->summary();
        if (!$summary['packages']) {
            return $this->redirect('/cart/', 303);
        }
        $gateway = Payments::gateway();
        $user = $this->app->auth()->user();
        $seo = Seo::make('Checkout');
        $seo->robots = 'noindex, nofollow';

        return $this->view('checkout/show', [
            'seo' => $seo,
            'summary' => $summary,
            'gateway' => $gateway?->clientConfig(),
            'user' => $user,
            'defaults' => $this->defaults($user),
            'states' => config('us_states'),
            'error' => $this->app->session()->getFlash('checkout_error'),
            'couponError' => $this->app->session()->getFlash('coupon_error'),
            'notices' => array_merge($summary['notices'], (array) $this->app->session()->getFlash('cart_notices', [])),
        ])->header('Cache-Control', 'private, no-store');
    }

    /** JSON: validate, create order + payment intent. */
    public function place(Request $request): Response
    {
        $gateway = Payments::gateway();
        if ($gateway === null) {
            return Response::json(['error' => 'Online payments are not available right now. Please contact us to complete your order.'], 503);
        }
        $data = $this->validateCheckout($request);
        if (isset($data['errors'])) {
            return Response::json(['error' => 'Please check the highlighted fields.', 'errors' => $data['errors']], 422);
        }

        $cart = $this->app->cart();
        $summary = $cart->summary($data['email']);
        if (!$summary['packages']) {
            return Response::json(['error' => 'Your cart is empty.'], 409);
        }
        // The browser showed a total; if anything changed since (stock, coupon, price), make the customer review it.
        $expected = (int) $request->input('expected_total');
        if ($expected > 0 && $expected !== (int) $summary['totals']['total_cents']) {
            return Response::json(['error' => 'Your order total changed to ' . money($summary['totals']['total_cents']) . '. Please review your order and try again.', 'reload' => true], 409);
        }

        $orders = new OrderService($this->app->db());
        $session = $this->app->session();
        // Re-submitting: the previous unpaid attempt is cancelled so it can't be paid twice.
        if ($previous = (int) $session->get('checkout_order_id', 0)) {
            $old = $orders->find($previous);
            if ($old && in_array($old['status'], ['pending', 'failed'], true)) {
                $orders->cancel($previous, 'Replaced by a new checkout attempt.');
            }
        }

        try {
            $referral = (new Referrals($this->app->db()))->forCheckout((string) $request->input('stylist_code', ''), (string) $request->cookie(Referrals::COOKIE, ''), $data['email'], $this->app->auth()->id());
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage(), 'errors' => ['stylist_code' => $e->getMessage()]], 422);
        }

        try {
            $order = $orders->createFromCart($summary, $data, $this->app->auth()->id(), $request->ip(), $request->userAgent(), $referral);
        } catch (\DomainException $e) {
            return Response::json(['error' => $e->getMessage(), 'reload' => true], 409);
        }
        try {
            $intent = $gateway->createIntent($order);
        } catch (StripeException $e) {
            $orders->markFailed((int) $order['id'], $e->getMessage());
            $this->app->logger()->error('PaymentIntent creation failed', ['order' => $order['number'], 'error' => $e->getMessage()]);
            return Response::json(['error' => 'We could not start the payment. Please try again in a moment.'], 502);
        }
        $orders->attachIntent((int) $order['id'], $intent['intent_id'], $gateway->name());
        $session->set('checkout_order_id', (int) $order['id']);

        return Response::json([
            'clientSecret' => $intent['client_secret'],
            'intentId' => $intent['intent_id'],
            'orderNumber' => $order['number'],
            'returnUrl' => $this->app->url()->to('/checkout/complete/', ['order' => $order['number'], 'key' => $order['access_key']]),
        ]);
    }

    /** Return from Stripe (or the local test gateway). */
    public function complete(Request $request): Response
    {
        $orders = new OrderService($this->app->db());
        $order = $orders->findByNumberAndKey($request->string('order'), $request->string('key')) ?? throw new HttpException(404);
        $thankYou = '/thank-you/?' . http_build_query(['order' => $order['number'], 'key' => $order['access_key']]);
        if (!in_array($order['status'], ['pending', 'failed'], true)) {
            return $this->redirect($thankYou, 303); // already processed (e.g. by the webhook)
        }
        $intentId = (string) $order['stripe_payment_intent_id'];
        $gateway = Payments::forIntent($intentId) ?? throw new HttpException(404);
        try {
            $intent = $gateway->fetchIntent($intentId);
        } catch (StripeException $e) {
            $this->app->session()->flash('checkout_error', 'We could not confirm your payment yet. If you were charged, your order will update automatically.');
            return $this->redirect('/checkout/', 303);
        }

        if ($intent['status'] === 'succeeded') {
            $orders->markPaid((int) $order['id'], $intent, $gateway);
            $this->finish();
            return $this->redirect($thankYou, 303);
        }
        if ($intent['status'] === 'processing') {
            // Bank/BNPL payments can take a moment; the webhook completes the order.
            $this->app->db()->update('orders', ['status' => 'on-hold'], ['id' => (int) $order['id']]);
            $orders->note((int) $order['id'], 'Payment processing with ' . OrderService::methodTitle($intent['method_type']) . '.');
            $this->finish();
            return $this->redirect($thankYou, 303);
        }
        $orders->markFailed((int) $order['id'], 'status ' . $intent['status']);
        $this->app->session()->flash('checkout_error', 'Your payment was not completed. Please try again or use another payment method.');
        return $this->redirect('/checkout/', 303);
    }

    /** Local test gateway only: approve or decline, then continue as Stripe would redirect. */
    public function testPay(Request $request): Response
    {
        if (!$this->app->isLocal()) {
            throw new HttpException(404);
        }
        $intentId = $request->string('intent');
        $gateway = Payments::forIntent($intentId);
        if (!$gateway instanceof TestGateway) {
            throw new HttpException(404);
        }
        $gateway->settle($intentId, $request->string('outcome') !== 'decline');
        return Response::json(['ok' => true]);
    }

    /** Stripe webhook: signature-verified, idempotent. */
    public function webhook(Request $request): Response
    {
        try {
            $event = StripeClient::verifyWebhook($request->body(), (string) $request->header('Stripe-Signature', ''), (string) Env::get('STRIPE_WEBHOOK_SECRET', ''));
        } catch (StripeException $e) {
            return Response::json(['error' => 'invalid signature'], 400);
        }
        $db = $this->app->db();
        if ($db->scalar('SELECT 1 FROM webhook_events WHERE id = ?', [$event['id']])) {
            return Response::json(['received' => true, 'duplicate' => true]);
        }

        $object = $event['data']['object'] ?? [];
        $orders = new OrderService($db);
        if (str_starts_with((string) $event['type'], 'payment_intent.')) {
            $orderId = (int) ($object['metadata']['order_id'] ?? 0);
            $order = $orderId ? $orders->find($orderId) : null;
            if ($order && $order['stripe_payment_intent_id'] === ($object['id'] ?? null)) {
                $gateway = Payments::forIntent((string) $object['id']);
                if ($event['type'] === 'payment_intent.succeeded' && $gateway) {
                    // Re-fetch rather than trusting the event body.
                    if ($order['status'] === 'on-hold' && !$order['paid_at']) {
                        $db->update('orders', ['status' => 'pending'], ['id' => $orderId]);
                    }
                    $orders->markPaid($orderId, $gateway->fetchIntent((string) $object['id']), $gateway);
                } elseif ($event['type'] === 'payment_intent.payment_failed') {
                    $orders->markFailed($orderId, (string) ($object['last_payment_error']['message'] ?? 'declined'));
                }
            }
        }
        if ($event['type'] === 'account.updated' && !empty($object['id'])) {
            (new StripeConnect($db))->apply($object);
        }
        $db->insert('webhook_events', ['id' => mb_substr_safe((string) $event['id'], 100), 'type' => mb_substr_safe((string) $event['type'], 100)]);
        return Response::json(['received' => true]);
    }

    /* -------------------------------------------------------------- helpers */

    private function finish(): void
    {
        $this->app->cart()->clear();
        $this->app->session()->forget('checkout_order_id');
    }

    /** @return array validated customer data, or ['errors' => [...]] */
    private function validateCheckout(Request $request): array
    {
        $states = implode(',', array_keys(config('us_states')));
        $rules = [
            'email' => 'required|email',
            'billing_first_name' => 'required|string|max:100',
            'billing_last_name' => 'required|string|max:100',
            'billing_address_1' => 'required|string|max:200',
            'billing_address_2' => 'nullable|string|max:200',
            'billing_city' => 'required|string|max:100',
            'billing_state' => 'required|in:' . $states,
            'billing_postcode' => 'required|regex:/^\d{5}(-\d{4})?$/',
            'billing_phone' => 'nullable|regex:/^[0-9 +().-]{7,30}$/',
            'order_comments' => 'nullable|string|max:1000',
            'terms' => 'nullable|boolean',
        ];
        $shipDifferent = in_array($request->input('ship_to_different_address'), ['1', 'on', 'true', true], true);
        if ($shipDifferent) {
            $rules += [
                'shipping_first_name' => 'required|string|max:100',
                'shipping_last_name' => 'required|string|max:100',
                'shipping_address_1' => 'required|string|max:200',
                'shipping_address_2' => 'nullable|string|max:200',
                'shipping_city' => 'required|string|max:100',
                'shipping_state' => 'required|in:' . $states,
                'shipping_postcode' => 'required|regex:/^\d{5}(-\d{4})?$/',
            ];
        }
        $v = new Validator($request->all(), $rules);
        if (!$v->passes()) {
            return ['errors' => $v->errors()];
        }
        $d = $v->validated();
        if (!$d['terms']) {
            return ['errors' => ['terms' => 'Please read and accept the terms and conditions to proceed with your order.']];
        }
        $address = static fn (string $p) => [
            'first_name' => $d[$p . '_first_name'], 'last_name' => $d[$p . '_last_name'], 'address_1' => $d[$p . '_address_1'],
            'address_2' => $d[$p . '_address_2'] ?? '', 'city' => $d[$p . '_city'], 'state' => $d[$p . '_state'],
            'postcode' => $d[$p . '_postcode'], 'country' => 'US',
        ];
        $billing = $address('billing') + ['phone' => $d['billing_phone'] ?? '', 'email' => strtolower($d['email'])];
        return [
            'email' => strtolower($d['email']),
            'billing' => $billing,
            'shipping' => $shipDifferent ? $address('shipping') : array_diff_key($billing, ['email' => 1, 'phone' => 1]),
            'note' => (string) ($d['order_comments'] ?? ''),
        ];
    }

    private function defaults(?array $user): array
    {
        // Saved account addresses first, then the last order's, then the account name.
        $last = $user ? $this->app->db()->first("SELECT billing_address, shipping_address FROM orders WHERE user_id = ? AND status <> 'pending' ORDER BY id DESC LIMIT 1", [$user['id']]) : null;
        $billing = json_decode((string) ($user['billing_address'] ?? ''), true) ?: ($last ? (json_decode($last['billing_address'], true) ?: []) : []);
        $shipping = json_decode((string) ($user['shipping_address'] ?? ''), true) ?: ($last ? (json_decode($last['shipping_address'], true) ?: []) : []);
        // Only open "ship to a different address" when it really differs.
        $same = static fn (array $a, array $b) => array_intersect_key($a, array_flip(['address_1', 'city', 'postcode'])) == array_intersect_key($b, array_flip(['address_1', 'city', 'postcode']));
        return [
            'email' => $user['email'] ?? '',
            'billing' => $billing + ['first_name' => $user['first_name'] ?? '', 'last_name' => $user['last_name'] ?? '', 'phone' => $user['phone'] ?? ''],
            'shipping' => $shipping && !$same($billing, $shipping) ? $shipping : [],
        ];
    }
}
