<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Security\PasswordHasher;
use App\Services\Cart\Pricing;
use App\Services\Orders\AdminOrders;
use App\Services\Orders\OrderService;
use App\Services\Settings;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorProducts;

/** Temporary user with the given roles, signed in for the duration of $fn. */
$asUser = static function (array $roles, callable $fn): void {
    $db = app()->db();
    $tag = bin2hex(random_bytes(3));
    $id = $db->insert('users', ['email' => "adm+{$tag}@nica.test", 'username' => "adm{$tag}", 'password_hash' => PasswordHasher::hash('a-long-password'), 'display_name' => 'A']);
    foreach ($roles as $role) {
        $db->insert('user_roles', ['user_id' => $id, 'role' => $role]);
    }
    app()->auth()->login($db->first('SELECT * FROM users WHERE id = ?', [$id]), '127.0.0.1', false);
    try {
        $fn($id);
    } finally {
        app()->auth()->logout();
        $db->delete('audit_log', ['user_id' => $id]);
        $db->delete('users', ['id' => $id]);
    }
};
$get = static fn (string $path) => app()->handle(Request::create('GET', $path))->status();
$post = static fn (string $path, array $data = []) => app()->handle(Request::create('POST', $path, $data + ['_token' => app()->csrf()->token()]));

test('admin: customers get 404, staff are limited, admins see everything', function () use ($asUser, $get) {
    $asUser(['customer'], function () use ($get) {
        assert_same(404, $get('/admin/'));
        assert_same(404, $get('/admin/payouts/'));
    });
    $asUser(['staff'], function () use ($get) {
        assert_same(200, $get('/admin/'));
        assert_same(200, $get('/admin/orders/'));
        assert_same(200, $get('/admin/vendors/'));
        assert_same(403, $get('/admin/payouts/'), 'no finance for staff');
        assert_same(403, $get('/admin/settings/'));
        assert_same(403, $get('/admin/orders/export'));
        assert_same(403, $get('/admin/audit/'));
    });
    $asUser(['admin'], function () use ($get) {
        assert_same(200, $get('/admin/payouts/'));
        assert_same(200, $get('/admin/settings/'));
    });
});

test('admin: log in as vendor is audited, blocks the admin panel and switches back', function () use ($asUser, $get, $post) {
    $db = app()->db();
    $vendor = $db->first("SELECT v.* FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.status = 'approved' AND u.status = 'active' LIMIT 1");
    $asUser(['admin'], function (int $adminId) use ($db, $vendor, $get, $post) {
        $res = $post('/admin/vendors/' . $vendor['id'] . '/impersonate');
        assert_contains('/dashboard/', (string) $res->getHeader('Location'));
        assert_same((int) $vendor['user_id'], app()->auth()->id());
        assert_same($adminId, app()->auth()->impersonatorId());
        assert_same(200, $get('/dashboard/'));
        assert_same(404, $get('/admin/'), 'no admin panel while acting as someone else');
        $post('/admin/impersonate/stop');
        assert_same($adminId, app()->auth()->id());
        assert_same(null, app()->auth()->impersonatorId());
        assert_same(2, (int) $db->scalar("SELECT COUNT(*) FROM audit_log WHERE user_id IN (?, ?) AND action LIKE 'vendor.impersonate%' AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE", [$adminId, $vendor['user_id']]));
        $db->query("DELETE FROM audit_log WHERE user_id = ? AND action = 'vendor.impersonate_end'", [$vendor['user_id']]);

        // Another admin can never be impersonated.
        $other = (int) $db->scalar("SELECT user_id FROM user_roles WHERE role = 'admin' AND user_id <> ? LIMIT 1", [$adminId]);
        assert_throws(DomainException::class, fn () => app()->auth()->impersonate($db->first('SELECT * FROM users WHERE id = ?', [$other])));
    });
});

test('admin refunds: partial then full refund, vendor share debited, stock restored', function () {
    $db = app()->db();
    $v = $db->first("SELECT pv.*, p.name, p.vendor_id, v.store_name FROM product_variants pv JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
                     WHERE pv.manage_stock = 1 AND pv.stock_qty >= 3 AND pv.is_active = 1 AND p.status = 'published' AND v.status = 'approved' LIMIT 1");
    if ($v === null) {
        return;
    }
    $line = ['variant_id' => (int) $v['id'], 'product_id' => (int) $v['product_id'], 'vendor_id' => (int) $v['vendor_id'], 'vendor_name' => $v['store_name'], 'name' => $v['name'],
        'variant_label' => null, 'sku' => null, 'image_media_id' => null, 'quantity' => 2, 'unit_cents' => (int) $v['regular_price_cents'], 'regular_cents' => (int) $v['regular_price_cents'],
        'on_sale' => false, 'category_ids' => [], 'shipping_class' => null];
    $summary = (new Pricing((array) Settings::get('commerce')))->calculate([$line]);
    $orders = new OrderService($db);
    $order = $orders->createFromCart($summary, ['email' => 'refund-test@nica.test', 'billing' => ['country' => 'US'], 'shipping' => ['country' => 'US'], 'note' => ''], null, '127.0.0.1', 'test');
    $intent = 'test_pi_' . $order['id'] . '_' . bin2hex(random_bytes(6));
    $db->update('orders', ['stripe_payment_intent_id' => $intent], ['id' => (int) $order['id']]);
    try {
        $orders->markPaid((int) $order['id'], ['id' => $intent, 'status' => 'succeeded', 'amount' => (int) $order['total_cents'], 'currency' => 'USD', 'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => null, 'method_type' => 'card']);
        $vo = $db->first('SELECT * FROM vendor_orders WHERE order_id = ?', [$order['id']]);
        $stockAfterSale = (int) $db->scalar('SELECT stock_qty FROM product_variants WHERE id = ?', [$v['id']]);
        $admin = new AdminOrders($db);

        assert_throws(DomainException::class, fn () => $admin->refund((int) $order['id'], (int) $vo['id'], (int) $vo['total_cents'] + 1, '', false, null), 'cannot refund more than paid');
        $r = $admin->refund((int) $order['id'], (int) $vo['id'], 1000, 'Damaged', true, null);
        assert_true($r['online'], 'goes through the payment gateway');
        $share = (int) round(1000 * (int) $vo['vendor_earning_cents'] / (int) $vo['total_cents']);
        assert_same(-$share, (int) $db->scalar("SELECT amount_cents FROM vendor_ledger WHERE vendor_order_id = ? AND type = 'refund'", [$vo['id']]));
        assert_same(null, $db->scalar("SELECT available_at FROM vendor_ledger WHERE vendor_order_id = ? AND type = 'refund'", [$vo['id']]), 'pending like the earning');
        assert_same('processing', $db->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        assert_same($stockAfterSale, (int) $db->scalar('SELECT stock_qty FROM product_variants WHERE id = ?', [$v['id']]), 'no restock on partial');

        $admin->refund((int) $order['id'], (int) $vo['id'], (int) $vo['total_cents'] - 1000, '', true, null);
        assert_same('refunded', $db->scalar('SELECT status FROM orders WHERE id = ?', [$order['id']]));
        assert_same('refunded', $db->scalar('SELECT status FROM vendor_orders WHERE id = ?', [$vo['id']]));
        assert_same($stockAfterSale + 2, (int) $db->scalar('SELECT stock_qty FROM product_variants WHERE id = ?', [$v['id']]), 'restocked');
        $net = (int) $db->scalar('SELECT SUM(amount_cents) FROM vendor_ledger WHERE vendor_order_id = ?', [$vo['id']]);
        assert_true(abs($net) <= 1, 'vendor nets zero after a full refund (rounding)');
        assert_throws(DomainException::class, fn () => $admin->refund((int) $order['id'], (int) $vo['id'], 1, '', false, null));
        assert_throws(DomainException::class, fn () => $admin->cancel((int) $order['id'], null), 'paid orders cannot be cancelled');
    } finally {
        $db->query("DELETE FROM email_queue WHERE subject LIKE ?", ['%#' . $order['number'] . '%']);
        $db->query('DELETE FROM vendor_ledger WHERE vendor_order_id IN (SELECT id FROM vendor_orders WHERE order_id = ?)', [$order['id']]);
        $db->delete('orders', ['id' => (int) $order['id']]);
        $db->update('product_variants', ['stock_qty' => (int) $v['stock_qty'], 'stock_status' => $v['stock_status']], ['id' => (int) $v['id']]);
        $db->query('UPDATE products SET sales_count = sales_count - LEAST(sales_count, 2) WHERE id = ?', [$v['product_id']]);
        (new App\Services\Catalog\ProductIndexer($db))->refresh((int) $v['product_id']);
    }
});

test('admin finance: declined payout frees the money, adjustments change the balance', function () {
    $db = app()->db();
    $vendorId = (int) $db->scalar("SELECT vendor_id FROM vendor_ledger GROUP BY vendor_id HAVING SUM(amount_cents) >= 10000 LIMIT 1");
    if (!$vendorId) {
        return;
    }
    $earnings = new Earnings($db);
    $before = $earnings->summary($vendorId);
    $req = $db->insert('payout_requests', ['vendor_id' => $vendorId, 'amount_cents' => 6000, 'method' => 'paypal']);
    try {
        assert_same($before['withdrawable'] - 6000, $earnings->summary($vendorId)['withdrawable']);
        assert_true($earnings->reject($req, null, 'Wrong PayPal email'));
        assert_false($earnings->reject($req, null, 'again'), 'only once');
        assert_same($before['withdrawable'], $earnings->summary($vendorId)['withdrawable']);
        $earnings->adjust($vendorId, -2500, 'Chargeback fee', null);
        assert_same($before['available'] - 2500, $earnings->summary($vendorId)['available']);
        assert_throws(DomainException::class, fn () => $earnings->adjust($vendorId, 0, 'zero', null));
    } finally {
        $db->delete('payout_requests', ['id' => $req]);
        $db->query("DELETE FROM vendor_ledger WHERE vendor_id = ? AND type = 'adjustment' AND description = 'Chargeback fee'", [$vendorId]);
    }
});

test('admin settings: saved overrides win over config and are audited; CSV cells cannot run formulas', function () use ($asUser, $post) {
    $db = app()->db();
    $saved = $db->select("SELECT name, value FROM settings WHERE name IN ('commerce','catalog','points','site','referrals','integrations')");
    $asUser(['admin'], function () use ($db, $post) {
        $res = $post('/admin/settings/', [
            'commission_rate' => '12.5', 'payout_threshold' => '75', 'tax_rate' => '9.75', 'tax_enabled' => '1', 'flat_rate' => '14.99', 'free_shipping_min' => '200',
            'class_cents' => ['american-wigs' => '29.99'], 'stock_hold_minutes' => '60', 'signup_points' => '10', 'points_per_dollar_redeemed' => '100', 'points_per_dollar_spent' => '0',
            'points_enabled' => '1', 'announcement' => 'Hello', 'contact_email' => 'inquiry@thenicacollection.com', 'contact_phone' => '+1 (423) 218 9054',
            'ref_enabled' => '1', 'ref_cookie_days' => '30', 'ref_threshold' => '25', 'ref_window_days' => '90',
            'tier_min' => ['bronze' => '0', 'silver' => '1000', 'gold' => '4000'], 'tier_rate' => ['bronze' => '5', 'silver' => '7.5', 'gold' => '12'],
        ]);
        assert_same(303, $res->status());
        Settings::flush();
        assert_same(12.5, (float) Settings::get('commerce.commission_rate'));
        assert_same(20000, (int) Settings::get('commerce.shipping.free_shipping.min_cents'));
        assert_same(7500, (int) Settings::get('commerce.payout_threshold_cents'));
        assert_same('tel:+14232189054', Settings::get('site.contact.phone_href'));
        assert_same('Hello', Settings::get('site.announcement'));
        assert_same(400000, (int) Settings::get('referrals.tiers')[2]['min_cents']);
        assert_same(12.0, (float) Settings::get('referrals.tiers')[2]['rate']);
        assert_true((bool) $db->scalar("SELECT 1 FROM audit_log WHERE action = 'settings.updated' AND user_id = ?", [app()->auth()->id()]));
    });
    $db->query("DELETE FROM settings WHERE name IN ('commerce','catalog','points','site','referrals','integrations')");
    foreach ($saved as $row) {
        $db->insert('settings', $row);
    }
    Settings::flush();

    $ctl = new App\Http\Controllers\Admin\AuditController(app());
    $m = new ReflectionMethod($ctl, 'csv');
    $m->setAccessible(true);
    $body = $m->invoke($ctl, 'x.csv', ['a', 'b'], [['=HYPERLINK("http://evil")', '-12.50']])->body();
    assert_contains("\"'=HYPERLINK", $body);
    assert_contains(',-12.50', $body, 'negative numbers stay numbers');
});

test('admin redirects: paths are normalised and loops refused', function () use ($asUser, $post) {
    $asUser(['admin'], function () use ($post) {
        $db = app()->db();
        $post('/admin/redirects/', ['from_path' => 'https://example.com/old-test-page', 'to_url' => '/shop/', 'status_code' => '301']);
        assert_same('/shop/', $db->scalar("SELECT to_url FROM redirects WHERE from_path = '/old-test-page/'"));
        $post('/admin/redirects/', ['from_path' => '/loop-test/', 'to_url' => '/loop-test', 'status_code' => '301']);
        assert_same(null, $db->scalar("SELECT id FROM redirects WHERE from_path = '/loop-test/'"));
        $post('/admin/redirects/', ['from_path' => '/js-test/', 'to_url' => 'javascript:alert(1)', 'status_code' => '302']);
        assert_same(null, $db->scalar("SELECT id FROM redirects WHERE from_path = '/js-test/'"));
        $db->query("DELETE FROM redirects WHERE from_path = '/old-test-page/'");
    });
});
