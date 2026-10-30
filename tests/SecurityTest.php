<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Security\PasswordHasher;
use App\Services\Cart\Pricing;
use App\Services\Mail\Mailer;
use App\Services\Orders\OrderService;
use App\Services\Points;
use App\Services\Settings;

/** Regression tests for the Phase 9 security review. */

$tempUser = static function (array $roles = ['customer'], array $extra = []): int {
    $db = app()->db();
    $tag = bin2hex(random_bytes(3));
    $id = $db->insert('users', ['email' => "sec+{$tag}@nica.test", 'username' => "sec{$tag}", 'password_hash' => PasswordHasher::hash('a-long-password'), 'display_name' => 'S'] + $extra);
    foreach ($roles as $r) {
        $db->insert('user_roles', ['user_id' => $id, 'role' => $r]);
    }
    return $id;
};
$dropUser = static function (int $id): void {
    $db = app()->db();
    $db->delete('audit_log', ['user_id' => $id]);
    $db->delete('points_ledger', ['user_id' => $id]);
    $db->delete('users', ['id' => $id]);
};

test('points: a declined payment keeps them reserved; cancelling returns them once; a late payment takes them again', function () use ($tempUser, $dropUser) {
    $db = app()->db();
    $userId = $tempUser();
    $points = new Points($db);
    $points->add($userId, 500, 'admin');
    $v = $db->first("SELECT pv.*, p.name, p.vendor_id, v.store_name FROM product_variants pv JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
                     WHERE pv.is_active = 1 AND p.status = 'published' AND v.status = 'approved' AND pv.regular_price_cents >= 2000 AND (pv.manage_stock = 0 OR pv.stock_qty >= 2) LIMIT 1");
    $line = ['variant_id' => (int) $v['id'], 'product_id' => (int) $v['product_id'], 'vendor_id' => (int) $v['vendor_id'], 'vendor_name' => $v['store_name'], 'name' => $v['name'],
        'variant_label' => null, 'sku' => null, 'image_media_id' => null, 'quantity' => 1, 'unit_cents' => (int) $v['regular_price_cents'], 'regular_cents' => (int) $v['regular_price_cents'],
        'on_sale' => false, 'category_ids' => [], 'shipping_class' => null];
    $summary = (new Pricing((array) Settings::get('commerce')))->calculate([$line]);
    // Redeem 500 points ($5) the way the cart does.
    $summary['totals']['points_used'] = 500;
    $summary['totals']['points_discount_cents'] = Points::value(500);
    $summary['totals']['total_cents'] -= Points::value(500);
    $orders = new OrderService($db);
    $order = $orders->createFromCart($summary, ['email' => 'sec-points@nica.test', 'billing' => ['country' => 'US'], 'shipping' => ['country' => 'US'], 'note' => ''], $userId, '127.0.0.1', 'test');
    try {
        $reserved = (int) $db->scalar('SELECT points_redeemed FROM orders WHERE id = ?', [$order['id']]);
        if ($reserved === 0) {
            return; // points disabled in this setup
        }
        assert_same(500 - $reserved, $points->balance($userId));
        $orders->markFailed((int) $order['id'], 'card_declined');
        assert_same(500 - $reserved, $points->balance($userId), 'declined card does not give points back');

        $orders->cancel((int) $order['id'], 'test');
        assert_same(500, $points->balance($userId));
        $orders->cancel((int) $order['id'], 'again');
        assert_same(500, $points->balance($userId), 'returned once');

        // The payment still goes through afterwards: it is flagged, not silently accepted.
        $intent = ['id' => 'test_pi_late', 'status' => 'succeeded', 'amount' => (int) $order['total_cents'], 'currency' => 'USD', 'metadata' => ['order_id' => (string) $order['id']], 'charge_id' => null, 'method_type' => 'card'];
        assert_same('cancelled', $orders->markPaid((int) $order['id'], $intent));
        assert_true((bool) $db->scalar("SELECT 1 FROM order_notes WHERE order_id = ? AND note LIKE '%after this order was cancelled%'", [$order['id']]));

        // A failed (not cancelled) order paid later with points already returned: points are taken again.
        $db->update('orders', ['status' => 'failed'], ['id' => (int) $order['id']]);
        assert_same('processing', $orders->markPaid((int) $order['id'], $intent));
        assert_same(500 - $reserved, $points->balance($userId), 'points re-deducted on late payment');
    } finally {
        $db->query('DELETE FROM email_queue WHERE subject LIKE ?', ['%#' . $order['number'] . '%']);
        $db->query('DELETE FROM vendor_ledger WHERE vendor_order_id IN (SELECT id FROM vendor_orders WHERE order_id = ?)', [$order['id']]);
        $db->delete('orders', ['id' => (int) $order['id']]);
        if ((int) $v['manage_stock']) {
            $db->update('product_variants', ['stock_qty' => (int) $v['stock_qty'], 'stock_status' => $v['stock_status']], ['id' => (int) $v['id']]);
        }
        $db->query('UPDATE products SET sales_count = sales_count - LEAST(sales_count, 1) WHERE id = ?', [$v['product_id']]);
        (new App\Services\Catalog\ProductIndexer($db))->refresh((int) $v['product_id']);
        $dropUser($userId);
    }
});

test('staff cannot add points to anyone, nobody can add their own; nobody approves their own stylist account', function () use ($tempUser, $dropUser) {
    $db = app()->db();
    $staff = $tempUser(['staff']);
    $customer = $tempUser();
    $admin = $tempUser(['admin']);
    $stylist = $db->insert('stylists', ['user_id' => $admin, 'code' => 'SELF' . strtoupper(bin2hex(random_bytes(2))), 'status' => 'pending', 'city' => 'X', 'state' => 'TN']);
    $post = static fn (string $path, array $d = []) => app()->handle(Request::create('POST', $path, $d + ['_token' => app()->csrf()->token()]))->status();
    try {
        app()->auth()->login($db->first('SELECT * FROM users WHERE id = ?', [$staff]), '127.0.0.1', false);
        assert_same(403, $post('/admin/customers/' . $staff . '/points', ['points' => '100000', 'note' => 'x']));
        assert_same(403, $post('/admin/customers/' . $customer . '/points', ['points' => '100', 'note' => 'x']));
        app()->auth()->login($db->first('SELECT * FROM users WHERE id = ?', [$admin]), '127.0.0.1', false);
        $post('/admin/customers/' . $admin . '/points', ['points' => '100000', 'note' => 'x']);
        assert_same(0, (new Points($db))->balance($admin), 'no points for oneself, even as admin');
        assert_same(403, $post('/admin/stylists/' . $stylist . '/approve'));
        assert_same('pending', $db->scalar('SELECT status FROM stylists WHERE id = ?', [$stylist]));
    } finally {
        app()->auth()->logout();
        $db->delete('stylists', ['id' => $stylist]);
        foreach ([$staff, $customer, $admin] as $id) {
            $dropUser($id);
        }
    }
});

test('reset link on a never-verified account clears 2FA; sent emails keep no body', function () use ($tempUser, $dropUser) {
    $db = app()->db();
    $id = $tempUser(['customer'], ['two_factor_secret' => 'x', 'two_factor_enabled_at' => gmdate('Y-m-d H:i:s')]);
    $token = bin2hex(random_bytes(32));
    $db->insert('password_resets', ['user_id' => $id, 'token_hash' => hash('sha256', $token), 'purpose' => 'reset', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 600)]);
    try {
        app()->handle(Request::create('POST', '/my-account/reset-password', ['_token' => app()->csrf()->token(), 'token' => $token, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password']));
        $u = $db->first('SELECT * FROM users WHERE id = ?', [$id]);
        assert_same(null, $u['two_factor_enabled_at']);
        assert_true($u['email_verified_at'] !== null);

        $mailId = (int) $db->insert('email_queue', ['to_email' => 'x@nica.test', 'subject' => 'sec test', 'html' => '<a href="/reset?token=SECRET">x</a>', 'send_after' => gmdate('Y-m-d H:i:s', time() - 1)]);
        (new Mailer())->work(50);
        $row = $db->first('SELECT status, html FROM email_queue WHERE id = ?', [$mailId]);
        assert_same('sent', $row['status']);
        assert_same('', $row['html']);
    } finally {
        app()->auth()->logout();
        $db->delete('password_resets', ['user_id' => $id]);
        $db->query("DELETE FROM email_queue WHERE subject = 'sec test'");
        $dropUser($id);
    }
});
