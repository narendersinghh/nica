<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Security\PasswordHasher;
use App\Services\Cart\Pricing;
use App\Services\Orders\AdminOrders;
use App\Services\Orders\OrderService;
use App\Services\Referrals\Referrals;
use App\Services\Settings;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorOrders;

/** An approved stylist and an order helper; everything is removed afterwards. */
$withStylist = static function (callable $fn): void {
    $db = app()->db();
    $tag = bin2hex(random_bytes(3));
    $userId = $db->insert('users', ['email' => "stylist+{$tag}@nica.test", 'username' => "sty{$tag}", 'password_hash' => PasswordHasher::hash('a-long-password'), 'first_name' => 'Jade', 'display_name' => 'Jade']);
    $code = 'JADE' . strtoupper($tag);
    $stylistId = $db->insert('stylists', ['user_id' => $userId, 'code' => $code, 'status' => 'approved', 'city' => 'Atlanta', 'state' => 'GA', 'approved_at' => gmdate('Y-m-d H:i:s')]);
    $orders = [];
    $variant = $db->first("SELECT pv.*, p.name, p.vendor_id, v.store_name FROM product_variants pv JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
                           WHERE pv.manage_stock = 1 AND pv.stock_qty >= 3 AND pv.is_active = 1 AND p.status = 'published' AND v.status = 'approved' AND pv.regular_price_cents >= 5000 LIMIT 1");
    $makeOrder = static function (string $email, ?string $ref) use ($db, $variant, &$orders): array {
        $line = ['variant_id' => (int) $variant['id'], 'product_id' => (int) $variant['product_id'], 'vendor_id' => (int) $variant['vendor_id'], 'vendor_name' => $variant['store_name'], 'name' => $variant['name'],
            'variant_label' => null, 'sku' => null, 'image_media_id' => null, 'quantity' => 1, 'unit_cents' => (int) $variant['regular_price_cents'], 'regular_cents' => (int) $variant['regular_price_cents'],
            'on_sale' => false, 'category_ids' => [], 'shipping_class' => null];
        $summary = (new Pricing((array) Settings::get('commerce')))->calculate([$line]);
        $service = new OrderService($db);
        $order = $service->createFromCart($summary, ['email' => $email, 'billing' => ['country' => 'US'], 'shipping' => ['country' => 'US'], 'note' => ''], null, '127.0.0.1', 'test', $ref);
        $intent = 'test_pi_' . $order['id'] . '_' . bin2hex(random_bytes(6));
        $db->update('orders', ['stripe_payment_intent_id' => $intent], ['id' => (int) $order['id']]);
        $service->markPaid((int) $order['id'], ['id' => $intent, 'status' => 'succeeded', 'amount' => (int) $order['total_cents'], 'currency' => 'USD', 'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => null, 'method_type' => 'card']);
        $orders[] = $order;
        return $db->first('SELECT * FROM orders WHERE id = ?', [$order['id']]);
    };
    try {
        if ($variant !== null) {
            $fn($db->first('SELECT s.*, u.email FROM stylists s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$stylistId]), $makeOrder);
        }
    } finally {
        foreach ($orders as $o) {
            $db->query('DELETE FROM email_queue WHERE subject LIKE ?', ['%#' . $o['number'] . '%']);
            $db->query('DELETE FROM vendor_ledger WHERE vendor_order_id IN (SELECT id FROM vendor_orders WHERE order_id = ?)', [$o['id']]);
            $db->delete('orders', ['id' => (int) $o['id']]); // cascades referrals + stylist ledger rows
        }
        if ($variant !== null) {
            $db->update('product_variants', ['stock_qty' => (int) $variant['stock_qty'], 'stock_status' => $variant['stock_status']], ['id' => (int) $variant['id']]);
            $db->query('UPDATE products SET sales_count = sales_count - LEAST(sales_count, ?) WHERE id = ?', [count($orders), $variant['product_id']]);
            (new App\Services\Catalog\ProductIndexer($db))->refresh((int) $variant['product_id']);
        }
        $db->delete('stylist_ledger', ['stylist_id' => $stylistId]);
        $db->delete('stylist_payout_requests', ['stylist_id' => $stylistId]);
        $db->delete('referral_clicks', ['stylist_id' => $stylistId]);
        $db->delete('stylists', ['id' => $stylistId]);
        $db->delete('users', ['id' => $userId]);
    }
};

test('referrals: ?ref= sets the cookie and counts one visit per visitor per day', function () use ($withStylist) {
    $withStylist(function (array $s) {
        $db = app()->db();
        $res = app()->handle(Request::create('GET', '/?ref=' . strtolower($s['code']), [], ['REMOTE_ADDR' => '10.20.30.40']));
        assert_same($s['code'], $res->getCookie(Referrals::COOKIE), 'code normalised to upper case');
        app()->handle(Request::create('GET', '/shop/?ref=' . $s['code'], [], ['REMOTE_ADDR' => '10.20.30.40']));
        app()->handle(Request::create('GET', '/?ref=' . $s['code'], [], ['REMOTE_ADDR' => '10.20.30.41']));
        assert_same(2, (int) $db->scalar('SELECT COUNT(*) FROM referral_clicks WHERE stylist_id = ?', [$s['id']]));
        assert_same(null, app()->handle(Request::create('GET', '/?ref=NOSUCHCODE'))->getCookie(Referrals::COOKIE));

        $ref = new Referrals($db);
        assert_same($s['code'], $ref->forCheckout('', $s['code'], 'client@nica.test', null));
        assert_same($s['code'], $ref->forCheckout(strtolower($s['code']), null, 'client@nica.test', null), 'typed code works');
        assert_same(null, $ref->forCheckout('', $s['code'], $s['email'], null), 'no commission on own orders');
        assert_same(null, $ref->forCheckout('', $s['code'], 'x@nica.test', (int) $s['user_id']));
        assert_throws(DomainException::class, fn () => $ref->forCheckout('NOSUCHCODE', null, 'client@nica.test', null));
    });
});

test('referrals: paid order earns tier commission, released on completion, clawed back on refund', function () use ($withStylist) {
    $withStylist(function (array $s, callable $makeOrder) {
        $db = app()->db();
        $order = $makeOrder('client+' . bin2hex(random_bytes(2)) . '@nica.test', $s['code']);
        $vo = $db->first('SELECT * FROM vendor_orders WHERE order_id = ?', [$order['id']]);
        $ref = $db->first('SELECT * FROM referrals WHERE order_id = ?', [$order['id']]);
        $base = (int) $vo['subtotal_cents'] - (int) $vo['discount_cents'];
        assert_same('bronze', $ref['tier']);
        assert_same(min((int) round($base * 0.05), (int) $vo['commission_cents']), (int) $ref['commission_cents']);
        assert_same((int) $vo['vendor_earning_cents'], (int) $db->scalar("SELECT amount_cents FROM vendor_ledger WHERE vendor_order_id = ? AND type = 'earning'", [$vo['id']]), 'vendor earning untouched');

        // Paying twice records one referral.
        (new OrderService($db))->markPaid((int) $order['id'], ['id' => $order['stripe_payment_intent_id'], 'status' => 'succeeded', 'amount' => (int) $order['total_cents'], 'currency' => 'USD', 'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => null, 'method_type' => 'card']);
        assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM referrals WHERE order_id = ?', [$order['id']]));

        $earnings = new Earnings($db, Earnings::STYLIST);
        assert_same(0, $earnings->summary((int) $s['id'])['withdrawable']);
        (new VendorOrders($db))->complete((int) $vo['vendor_id'], (int) $vo['id'], null);
        assert_same('earned', $db->scalar('SELECT status FROM referrals WHERE order_id = ?', [$order['id']]));
        assert_same((int) $ref['commission_cents'], $earnings->summary((int) $s['id'])['withdrawable']);

        // Stylist payout uses the same rules as vendors (threshold, details, one open request).
        $earnings->saveDetails((int) $s['id'], 'paypal', ['email' => 'jade@nica.test']);
        if ((int) $ref['commission_cents'] >= 2500) {
            $req = $earnings->request((int) $s['id'], 2500);
            assert_same((int) $ref['commission_cents'] - 2500, $earnings->summary((int) $s['id'])['withdrawable']);
            $earnings->cancel((int) $s['id'], $req);
        }
        assert_throws(DomainException::class, fn () => $earnings->request((int) $s['id'], 100), 'below the stylist minimum');

        // Full refund takes the commission back.
        (new AdminOrders($db))->refund((int) $order['id'], (int) $vo['id'], (int) $vo['total_cents'], 'test', false, null);
        assert_same('void', $db->scalar('SELECT status FROM referrals WHERE order_id = ?', [$order['id']]));
        $net = (int) $db->scalar('SELECT SUM(amount_cents) FROM stylist_ledger WHERE order_id = ?', [$order['id']]);
        assert_true(abs($net) <= 1, 'stylist nets zero after a full refund');
    });
});

test('referrals: own orders earn nothing; tier override changes the rate', function () use ($withStylist) {
    $withStylist(function (array $s, callable $makeOrder) {
        $db = app()->db();
        $own = $makeOrder($s['email'], $s['code']);
        assert_same(null, $db->scalar('SELECT id FROM referrals WHERE order_id = ?', [$own['id']]), 'self-referral ignored even if the code got onto the order');

        $db->update('stylists', ['tier_override' => 'gold'], ['id' => (int) $s['id']]);
        $tier = (new Referrals($db))->tier($db->first('SELECT * FROM stylists WHERE id = ?', [$s['id']]));
        assert_same('gold', $tier['key']);
        $order = $makeOrder('client2+' . bin2hex(random_bytes(2)) . '@nica.test', $s['code']);
        assert_same('10.00', $db->scalar('SELECT rate FROM referrals WHERE order_id = ?', [$order['id']]));

        $db->update('stylists', ['status' => 'suspended'], ['id' => (int) $s['id']]);
        $later = $makeOrder('client3+' . bin2hex(random_bytes(2)) . '@nica.test', $s['code']);
        assert_same(null, $db->scalar('SELECT id FROM referrals WHERE order_id = ?', [$later['id']]), 'suspended stylists stop earning');
    });
});

test('stylist sign-up: join form creates a pending application with a unique code', function () {
    $db = app()->db();
    $tag = bin2hex(random_bytes(3));
    $id = $db->insert('users', ['email' => "join+{$tag}@nica.test", 'username' => "join{$tag}", 'password_hash' => PasswordHasher::hash('a-long-password'), 'first_name' => 'Maya', 'display_name' => 'Maya']);
    app()->auth()->login($db->first('SELECT * FROM users WHERE id = ?', [$id]), '127.0.0.1', false);
    try {
        assert_contains('Apply to the program', app()->handle(Request::create('GET', '/my-account/stylist/'))->body());
        $post = static fn (array $d) => app()->handle(Request::create('POST', '/my-account/stylist/join', $d + ['_token' => app()->csrf()->token()]));
        $post(['code' => 'NICA', 'city' => 'Austin', 'state' => 'TX', 'terms' => '1']);
        assert_same(null, $db->scalar('SELECT id FROM stylists WHERE user_id = ?', [$id]), 'reserved code refused');
        $post(['code' => 'maya' . $tag, 'city' => 'Austin', 'state' => 'TX', 'terms' => '1', 'instagram' => '@maya']);
        $row = $db->first('SELECT * FROM stylists WHERE user_id = ?', [$id]);
        assert_same('pending', $row['status']);
        assert_same(strtoupper('maya' . $tag), $row['code']);
        assert_same('maya', $row['instagram']);
        assert_contains('Application received', app()->handle(Request::create('GET', '/my-account/stylist/'))->body());
        assert_same(null, (new Referrals($db))->approved($row['code']), 'pending codes do not earn');
    } finally {
        app()->auth()->logout();
        $db->query('DELETE FROM email_queue WHERE subject LIKE ?', ['%stylist application%']);
        $db->delete('audit_log', ['user_id' => $id]);
        $db->delete('stylists', ['user_id' => $id]);
        $db->delete('users', ['id' => $id]);
    }
});
