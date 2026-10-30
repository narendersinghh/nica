<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Security\Crypto;
use App\Core\Security\PasswordHasher;
use App\Services\Cart\Pricing;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorOrders;
use App\Services\Vendor\VendorProducts;

test('vendor products: variation keys, combinations and money parsing', function () {
    assert_same('1-3_2-9', VendorProducts::comboKey([2 => 9, 1 => 3]), 'key is order independent');
    assert_same('simple', VendorProducts::comboKey([]));
    $combos = VendorProducts::combinations([1 => [3, 5], 2 => [9]]);
    assert_same(['1-3_2-9', '1-5_2-9'], array_keys($combos));
    assert_same([], VendorProducts::combinations([]));
    assert_same(12950, VendorProducts::parseMoney('$129.50'));
    assert_same(100000, VendorProducts::parseMoney('1,000'));
    assert_same(null, VendorProducts::parseMoney('12.345'));
    assert_same(null, VendorProducts::parseMoney('-5'));
    assert_same(null, VendorProducts::parseMoney(''));
});

test('crypto: payout details round-trip, are bound to their purpose and detect tampering', function () {
    $blob = Crypto::encrypt('{"email":"a@b.c"}', 'nica-payout-details');
    assert_same('{"email":"a@b.c"}', Crypto::decrypt($blob, 'nica-payout-details'));
    assert_same(null, Crypto::decrypt($blob, 'nica-2fa'), 'other purpose key fails');
    $blob[30] = chr(ord($blob[30]) ^ 1);
    assert_same(null, Crypto::decrypt($blob, 'nica-payout-details'));
    assert_same('PayPal · j***@example.com', Earnings::mask('paypal', ['email' => 'jane@example.com']));
    assert_same('Bank · Chase ••••6789', Earnings::mask('bank', ['bank_name' => 'Chase', 'account_number' => '123456789']));
});

/** A throw-away approved vendor (and its owner); everything is removed afterwards. */
$withVendor = static function (callable $fn): void {
    $db = app()->db();
    $tag = bin2hex(random_bytes(3));
    $userId = $db->insert('users', ['email' => "vendor+{$tag}@nica.test", 'username' => "vendor{$tag}", 'password_hash' => PasswordHasher::hash('a-long-password'), 'display_name' => 'V']);
    $db->insert('user_roles', ['user_id' => $userId, 'role' => 'vendor']);
    $vendorId = $db->insert('vendors', ['user_id' => $userId, 'slug' => "test-store-{$tag}", 'store_name' => "Test Store {$tag}", 'status' => 'approved']);
    try {
        $fn($db->first('SELECT * FROM vendors WHERE id = ?', [$vendorId]), $userId);
    } finally {
        $orderIds = array_column($db->select('SELECT DISTINCT order_id FROM vendor_orders WHERE vendor_id = ?', [$vendorId]), 'order_id');
        foreach ($orderIds as $oid) {
            $number = $db->scalar('SELECT number FROM orders WHERE id = ?', [$oid]);
            $db->query('DELETE FROM email_queue WHERE subject LIKE ?', ['%#' . $number . '%']);
            $db->query('DELETE FROM vendor_ledger WHERE vendor_order_id IN (SELECT id FROM vendor_orders WHERE order_id = ?)', [$oid]);
            $db->delete('orders', ['id' => (int) $oid]);
        }
        $db->delete('vendor_ledger', ['vendor_id' => $vendorId]);
        $db->delete('payout_requests', ['vendor_id' => $vendorId]);
        $db->delete('coupons', ['vendor_id' => $vendorId]);
        foreach ($db->select('SELECT id FROM products WHERE vendor_id = ?', [$vendorId]) as $p) {
            $db->query('DELETE FROM variant_values WHERE variant_id IN (SELECT id FROM product_variants WHERE product_id = ?)', [$p['id']]);
            foreach (['product_variants', 'product_attribute_values', 'product_category', 'product_tag', 'product_images'] as $t) {
                $db->delete($t, ['product_id' => (int) $p['id']]);
            }
            $db->delete('products', ['id' => (int) $p['id']]);
        }
        $db->delete('vendors', ['id' => $vendorId]);
        $db->delete('users', ['id' => $userId]);
    }
};

test('vendor products: variable product saves a matrix, details stay details, removed lengths switch off', function () use ($withVendor) {
    $withVendor(function (array $vendor, int $userId) {
        $db = app()->db();
        $length = (int) $db->scalar("SELECT id FROM attributes WHERE slug = 'length'");
        $color = (int) $db->scalar("SELECT id FROM attributes WHERE slug = 'color'");
        [$l1, $l2] = array_map('intval', array_column($db->select('SELECT id FROM attribute_values WHERE attribute_id = ? ORDER BY sort_order LIMIT 2', [$length]), 'id'));
        $black = (int) $db->scalar('SELECT id FROM attribute_values WHERE attribute_id = ? LIMIT 1', [$color]);
        $cat = (int) $db->scalar('SELECT id FROM product_categories LIMIT 1');
        $products = new VendorProducts($db);
        $k1 = $length . '-' . $l1;
        $k2 = $length . '-' . $l2;
        $input = [
            'name' => 'Test Bundle <b>', 'type' => 'variable', 'intent' => 'publish', 'categories' => [$cat],
            'attribute_values' => [$l1, $l2, $black], 'variation_attributes' => [$length],
            'description' => '<p>Soft</p><script>alert(1)</script>',
            'variants' => [$k1 => ['enabled' => '1', 'price' => '100', 'sku' => 'TB-1'], $k2 => ['enabled' => '1', 'price' => '120.50', 'sale_price' => '110', 'stock_qty' => '3']],
        ];
        $r = $products->save($vendor, null, $input, [], $userId);
        assert_same([], $r['errors']);
        $p = $products->find((int) $vendor['id'], $r['id']);
        assert_same('published', $p['status']);
        assert_same('test-bundle-b', $p['slug']);
        assert_false(str_contains((string) $p['description'], '<script'), 'description sanitised');
        assert_same(10000, (int) $p['price_min_cents']);
        assert_same(11000, (int) $p['price_max_cents'], 'sale price counts');
        assert_same(2, (int) $db->scalar('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_active = 1', [$r['id']]));
        assert_same(0, (int) $db->scalar('SELECT used_for_variants FROM product_attribute_values WHERE product_id = ? AND attribute_value_id = ?', [$r['id'], $black]), 'color is a detail');

        // Editor round trip: saved variants come back under the same keys.
        $data = $products->editorData($p);
        assert_same([$k1, $k2], array_keys($data['variants']));

        // Drop the second length: its variant is switched off, not deleted.
        $input['attribute_values'] = [$l1, $black];
        unset($input['variants'][$k2]);
        $products->save($vendor, $p, $input, [], $userId);
        assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND is_active = 1', [$r['id']]));
        assert_same(2, (int) $db->scalar('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$r['id']]));

        // Publishing without a price fails; another vendor cannot load the product.
        $bad = $products->save($vendor, null, ['name' => 'No price', 'type' => 'simple', 'intent' => 'publish', 'categories' => [$cat], 'variants' => ['simple' => ['price' => '']]], [], $userId);
        assert_true(isset($bad['errors']['price']));
        assert_same(null, $products->find((int) $vendor['id'] + 100000, $r['id']));

        // Duplicate SKU inside the store is refused.
        $dup = $products->save($vendor, null, ['name' => 'Dup', 'type' => 'simple', 'intent' => 'draft', 'variants' => ['simple' => ['price' => '5', 'sku' => 'TB-1']]], [], $userId);
        assert_true(isset($dup['errors']['variants']));

        $copy = $products->duplicate((int) $vendor['id'], $r['id']);
        assert_same('draft', $db->scalar('SELECT status FROM products WHERE id = ?', [$copy]));
        assert_same(null, $db->scalar('SELECT sku FROM product_variants WHERE product_id = ? AND sku IS NOT NULL LIMIT 1', [$copy]), 'SKUs are not copied');
    });
});

test('vendor orders + payouts: complete releases the earning, threshold and double requests enforced', function () use ($withVendor) {
    $withVendor(function (array $vendor, int $userId) {
        $db = app()->db();
        $products = new VendorProducts($db);
        $cat = (int) $db->scalar('SELECT id FROM product_categories LIMIT 1');
        $pid = $products->save($vendor, null, ['name' => 'Payout Wig', 'type' => 'simple', 'intent' => 'publish', 'categories' => [$cat], 'variants' => ['simple' => ['price' => '400', 'stock_qty' => '5']]], [], $userId)['id'];
        $variant = $db->first('SELECT * FROM product_variants WHERE product_id = ?', [$pid]);
        $line = ['variant_id' => (int) $variant['id'], 'product_id' => $pid, 'vendor_id' => (int) $vendor['id'], 'vendor_name' => $vendor['store_name'],
            'name' => 'Payout Wig', 'variant_label' => null, 'sku' => null, 'image_media_id' => null, 'quantity' => 1,
            'unit_cents' => 40000, 'regular_cents' => 40000, 'on_sale' => false, 'category_ids' => [], 'shipping_class' => null];
        $summary = (new Pricing((array) Settings::get('commerce')))->calculate([$line]);
        $customer = ['email' => 'vendor-flow@nica.test', 'billing' => ['first_name' => 'T', 'last_name' => 'T', 'country' => 'US'], 'shipping' => ['country' => 'US'], 'note' => ''];
        $orders = new OrderService($db);
        $order = $orders->createFromCart($summary, $customer, null, '127.0.0.1', 'test');
        $orders->markPaid((int) $order['id'], ['id' => 'test_pi_v', 'status' => 'succeeded', 'amount' => (int) $order['total_cents'], 'currency' => 'USD', 'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => null, 'method_type' => 'card']);
        $vo = $db->first('SELECT * FROM vendor_orders WHERE order_id = ?', [$order['id']]);

        $earnings = new Earnings($db);
        $vid = (int) $vendor['id'];
        assert_same(0, $earnings->summary($vid)['withdrawable'], 'not withdrawable before completion');
        assert_same((int) $vo['vendor_earning_cents'], $earnings->summary($vid)['pending']);

        $vendorOrders = new VendorOrders($db);
        assert_throws(DomainException::class, fn () => $vendorOrders->complete($vid + 100000, (int) $vo['id'], $userId), 'other vendors cannot touch it');
        $vendorOrders->ship($vid, (int) $vo['id'], 'usps', '9400 1000 0000', $userId);
        $shipped = $db->first('SELECT * FROM vendor_orders WHERE id = ?', [$vo['id']]);
        assert_same('shipped', $shipped['status']);
        assert_contains('tLabels=9400%201000%200000', (string) $shipped['tracking_url']);
        $vendorOrders->complete($vid, (int) $vo['id'], $userId);
        assert_same('completed', $db->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]), 'single-vendor order completes');
        assert_throws(DomainException::class, fn () => $vendorOrders->ship($vid, (int) $vo['id'], 'usps', '123456', $userId));

        $balance = $earnings->summary($vid)['withdrawable'];
        assert_same((int) $vo['vendor_earning_cents'], $balance);

        assert_throws(DomainException::class, fn () => $earnings->request($vid, 6000), 'needs payout details first');
        $earnings->saveDetails($vid, 'paypal', ['email' => 'v@nica.test']);
        assert_throws(DomainException::class, fn () => $earnings->request($vid, 4999), 'below threshold');
        assert_throws(DomainException::class, fn () => $earnings->request($vid, $balance + 1), 'more than balance');
        $req = $earnings->request($vid, $balance);
        assert_throws(DomainException::class, fn () => $earnings->request($vid, 5000), 'one open request at a time');
        assert_same(0, $earnings->summary($vid)['withdrawable'], 'requested money is held');
        assert_true($earnings->cancel($vid, $req));
        $req = $earnings->request($vid, 5000);
        $earnings->markPaid($req, null, 'PP-123');
        assert_same($balance - 5000, $earnings->summary($vid)['available']);
        assert_throws(DomainException::class, fn () => $earnings->markPaid($req, null), 'paid once only');

        $db->update('product_variants', ['stock_qty' => 5], ['id' => (int) $variant['id']]);
    });
});

test('dashboard: guests go to login, customers without a store get 404', function () {
    $res = app()->handle(Request::create('GET', '/dashboard/'));
    assert_same(302, $res->status());
    $db = app()->db();
    $customer = $db->first("SELECT u.* FROM users u WHERE NOT EXISTS (SELECT 1 FROM vendors v WHERE v.user_id = u.id) LIMIT 1");
    app()->auth()->login($customer, '127.0.0.1');
    try {
        assert_same(404, app()->handle(Request::create('GET', '/dashboard/'))->status());
        assert_same(404, app()->handle(Request::create('GET', '/dashboard/orders/1/slip/'))->status());
    } finally {
        app()->auth()->logout();
    }
});
