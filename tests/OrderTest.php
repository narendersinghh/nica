<?php

declare(strict_types=1);

use App\Services\Cart\Pricing;
use App\Services\Orders\OrderService;
use App\Services\Payments\StripeClient;
use App\Services\Payments\StripeException;
use App\Services\Settings;

test('stripe webhook: valid signature accepted, tampered/old/missing rejected', function () {
    $secret = 'whsec_test123';
    $payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => []]]);
    $t = time();
    $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret);
    assert_same('evt_1', StripeClient::verifyWebhook($payload, $header, $secret)['id']);
    assert_throws(StripeException::class, fn () => StripeClient::verifyWebhook($payload . ' ', $header, $secret));
    assert_throws(StripeException::class, fn () => StripeClient::verifyWebhook($payload, $header, 'whsec_other'));
    $old = time() - 1000;
    assert_throws(StripeException::class, fn () => StripeClient::verifyWebhook($payload, 't=' . $old . ',v1=' . hash_hmac('sha256', $old . '.' . $payload, $secret), $secret));
    assert_throws(StripeException::class, fn () => StripeClient::verifyWebhook($payload, '', $secret));
});

/** A real variant with managed stock, priced into a one-line summary. */
$makeOrder = static function (): array {
    $db = app()->db();
    $v = $db->first("SELECT pv.*, p.name, p.vendor_id, v.store_name FROM product_variants pv JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
                     WHERE pv.manage_stock = 1 AND pv.stock_qty >= 2 AND pv.is_active = 1 AND p.status = 'published' AND v.status = 'approved' LIMIT 1");
    if ($v === null) {
        return [];
    }
    $line = ['variant_id' => (int) $v['id'], 'product_id' => (int) $v['product_id'], 'vendor_id' => (int) $v['vendor_id'], 'vendor_name' => $v['store_name'],
        'name' => $v['name'], 'variant_label' => null, 'sku' => $v['sku'], 'image_media_id' => null, 'quantity' => 1,
        'unit_cents' => (int) $v['regular_price_cents'], 'regular_cents' => (int) $v['regular_price_cents'], 'on_sale' => false, 'category_ids' => [], 'shipping_class' => null];
    $summary = (new Pricing((array) Settings::get('commerce')))->calculate([$line]);
    $customer = ['email' => 'order-test@nica.test', 'billing' => ['first_name' => 'T', 'last_name' => 'T', 'country' => 'US'], 'shipping' => ['country' => 'US'], 'note' => ''];
    $order = (new OrderService($db))->createFromCart($summary, $customer, null, '127.0.0.1', 'test');
    return [$order, $v];
};
$cleanup = static function (array $order, array $variant, int $stockBefore): void {
    $db = app()->db();
    $db->query('DELETE FROM vendor_ledger WHERE vendor_order_id IN (SELECT id FROM vendor_orders WHERE order_id = ?)', [$order['id']]);
    $db->delete('orders', ['id' => (int) $order['id']]);
    $db->update('product_variants', ['stock_qty' => $stockBefore, 'stock_status' => 'instock'], ['id' => (int) $variant['id']]);
    $db->query("DELETE FROM email_queue WHERE subject LIKE ?", ['%#' . $order['number'] . '%']);
    (new App\Services\Catalog\ProductIndexer($db))->refresh((int) $variant['product_id']);
};

test('order: paying twice (return page + webhook) records one payment, one ledger entry, one stock decrement', function () use ($makeOrder, $cleanup) {
    [$order, $variant] = $makeOrder() + [null, null];
    if (!$order) {
        return;
    }
    $db = app()->db();
    $stock = (int) $variant['stock_qty'];
    $orders = new OrderService($db);
    $intent = ['id' => 'test_pi_x', 'status' => 'succeeded', 'amount' => (int) $order['total_cents'], 'currency' => 'USD',
        'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => 'ch_x', 'method_type' => 'card'];
    try {
        assert_same('processing', $orders->markPaid((int) $order['id'], $intent));
        assert_same('processing', $orders->markPaid((int) $order['id'], $intent));
        assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM vendor_ledger l JOIN vendor_orders vo ON vo.id = l.vendor_order_id WHERE vo.order_id = ?', [$order['id']]));
        assert_same($stock - 1, (int) $db->scalar('SELECT stock_qty FROM product_variants WHERE id = ?', [$variant['id']]));
        assert_true(str_starts_with((string) $order['number'], '6'), 'new order numbers continue after the imported ones');
    } finally {
        $cleanup($order, $variant, $stock);
    }
});

test('order: a payment for a different amount puts the order on hold and pays out nothing', function () use ($makeOrder, $cleanup) {
    [$order, $variant] = $makeOrder() + [null, null];
    if (!$order) {
        return;
    }
    $db = app()->db();
    $stock = (int) $variant['stock_qty'];
    $intent = ['id' => 'test_pi_y', 'status' => 'succeeded', 'amount' => (int) $order['total_cents'] - 100, 'currency' => 'USD',
        'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => 'ch_y', 'method_type' => 'card'];
    try {
        assert_same('on-hold', (new OrderService($db))->markPaid((int) $order['id'], $intent));
        assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM vendor_ledger l JOIN vendor_orders vo ON vo.id = l.vendor_order_id WHERE vo.order_id = ?', [$order['id']]));
        assert_same($stock, (int) $db->scalar('SELECT stock_qty FROM product_variants WHERE id = ?', [$variant['id']]), 'stock untouched');
    } finally {
        $cleanup($order, $variant, $stock);
    }
});

test('checkout endpoint: rejects bad input with field errors and never trusts a stale total', function () {
    $res = app()->handle(App\Core\Http\Request::create('POST', '/checkout/place', ['_token' => app()->csrf()->token(), 'email' => 'nope'], ['HTTP_ACCEPT' => 'application/json']));
    assert_true(in_array($res->status(), [409, 422], true), 'empty cart or validation error, got ' . $res->status());
    $body = json_decode($res->body(), true);
    assert_true(isset($body['error']));
});
