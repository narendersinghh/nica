<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Orders\AdminOrders;
use App\Services\Orders\OrderService;
use App\Services\Vendor\VendorOrders;
use App\Services\Vendor\VendorProducts;

final class OrdersController extends AdminController
{
    public const STATUSES = ['processing' => 'Processing', 'on-hold' => 'On hold', 'completed' => 'Completed', 'pending' => 'Pending payment', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed'];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        [$where, $params, $status, $search] = $this->filters($request);
        $page = $this->page($request);
        $total = (int) $db->scalar("SELECT COUNT(*) FROM orders o WHERE {$where}", $params);
        $rows = $db->select(
            "SELECT o.id, o.number, o.email, o.status, o.total_cents, o.refunded_cents, o.billing_address, o.created_at, o.payment_method_title,
                    (SELECT GROUP_CONCAT(v.store_name SEPARATOR ', ') FROM vendor_orders vo JOIN vendors v ON v.id = vo.vendor_id WHERE vo.order_id = o.id) AS vendors
             FROM orders o WHERE {$where} ORDER BY o.created_at DESC LIMIT 25 OFFSET " . (($page - 1) * 25),
            $params
        );
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($db->select('SELECT status, COUNT(*) n FROM orders GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $this->panel($request, 'admin/orders', ['title' => 'Orders', 'tab' => 'orders', 'rows' => $rows, 'counts' => $counts, 'status' => $status, 'search' => $search, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25))]);
    }

    public function export(Request $request): Response
    {
        $this->authorize('finance');
        [$where, $params] = $this->filters($request);
        $rows = $this->app->db()->select(
            "SELECT o.number, o.created_at, o.status, o.email, o.subtotal_cents, o.discount_cents, o.shipping_cents, o.tax_cents, o.total_cents, o.refunded_cents,
                    (SELECT SUM(commission_cents) FROM vendor_orders vo WHERE vo.order_id = o.id) commission, o.payment_method_title, o.stripe_payment_intent_id
             FROM orders o WHERE {$where} ORDER BY o.created_at",
            $params
        );
        Audit::log('orders.exported', null, null, ['rows' => count($rows)]);
        $c = static fn ($v) => number_format((int) $v / 100, 2, '.', '');
        return $this->csv('orders-' . gmdate('Y-m-d') . '.csv',
            ['Order', 'Date (UTC)', 'Status', 'Email', 'Subtotal', 'Discount', 'Shipping', 'Tax', 'Total', 'Refunded', 'Commission', 'Payment method', 'Stripe payment'],
            array_map(static fn ($r) => [$r['number'], $r['created_at'], $r['status'], $r['email'], $c($r['subtotal_cents']), $c($r['discount_cents']), $c($r['shipping_cents']), $c($r['tax_cents']), $c($r['total_cents']), $c($r['refunded_cents']), $c($r['commission']), $r['payment_method_title'], $r['stripe_payment_intent_id']], $rows));
    }

    public function show(Request $request): Response
    {
        $order = $this->order($request);
        $db = $this->app->db();
        $orders = new OrderService($db);
        $vendorOrders = $db->select('SELECT vo.*, v.store_name FROM vendor_orders vo JOIN vendors v ON v.id = vo.vendor_id WHERE vo.order_id = ? ORDER BY vo.id', [$order['id']]);
        $items = $orders->items((int) $order['id']);
        foreach ($vendorOrders as &$vo) {
            $vo['items'] = array_values(array_filter($items, static fn ($i) => (int) $i['vendor_order_id'] === (int) $vo['id']));
        }
        unset($vo);
        return $this->panel($request, 'admin/order', [
            'title' => 'Order #' . $order['number'],
            'tab' => 'orders',
            'back' => ['Orders', '/admin/orders/'],
            'order' => $order,
            'vendorOrders' => $vendorOrders,
            'notes' => $db->select('SELECT n.*, u.email AS author FROM order_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.order_id = ? ORDER BY n.created_at DESC, n.id DESC', [$order['id']]),
            'refunds' => $db->select('SELECT r.*, u.email AS author FROM refunds r LEFT JOIN users u ON u.id = r.created_by WHERE r.order_id = ? ORDER BY r.created_at DESC', [$order['id']]),
            'customer' => $order['user_id'] ? $db->first('SELECT id, email, first_name, last_name FROM users WHERE id = ?', [$order['user_id']]) : null,
        ]);
    }

    public function refund(Request $request): Response
    {
        $this->authorize('orders');
        $order = $this->order($request);
        $data = $this->validate($request, ['vendor_order_id' => 'required|integer', 'amount' => 'required|string|max:12', 'reason' => 'nullable|string|max:500', 'restock' => 'nullable|boolean']);
        $cents = VendorProducts::parseMoney($data['amount']);
        try {
            if ($cents === null) {
                throw new \DomainException('Enter an amount like 25.00.');
            }
            $result = (new AdminOrders($this->app->db()))->refund((int) $order['id'], (int) $data['vendor_order_id'], $cents, trim((string) ($data['reason'] ?? '')), !empty($data['restock']), $this->app->auth()->id());
            Audit::log('order.refunded', 'order', (int) $order['id'], ['vendor_order_id' => (int) $data['vendor_order_id'], 'amount_cents' => $cents, 'online' => $result['online']]);
            $this->notice($result['online'] ? 'Refunded ' . money($cents) . ' to the customer\'s card.' : 'Refund of ' . money($cents) . ' recorded. Send the money back from the Stripe dashboard (this order was paid on the old site).');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/admin/orders/' . $order['id'] . '/');
    }

    /** cancel | release | complete */
    public function action(Request $request): Response
    {
        $order = $this->order($request);
        $action = (string) $request->param('action');
        $admin = $this->app->auth()->id();
        $db = $this->app->db();
        try {
            match ($action) {
                'cancel' => (new AdminOrders($db))->cancel((int) $order['id'], $admin),
                'release' => (new AdminOrders($db))->release((int) $order['id'], $admin),
                'complete' => array_map(
                    static fn ($vo) => (new VendorOrders($db))->complete((int) $vo['vendor_id'], (int) $vo['id'], $admin),
                    $db->select("SELECT id, vendor_id FROM vendor_orders WHERE order_id = ? AND status IN ('processing','shipped')", [$order['id']])
                ),
                default => throw new HttpException(404),
            };
            Audit::log('order.' . $action, 'order', (int) $order['id']);
            $this->notice(['cancel' => 'Order cancelled.', 'release' => 'Order released to the vendors.', 'complete' => 'Order completed. Vendor earnings are now available.'][$action]);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/admin/orders/' . $order['id'] . '/');
    }

    public function note(Request $request): Response
    {
        $order = $this->order($request);
        $data = $this->validate($request, ['note' => 'required|string|max:2000', 'to_customer' => 'nullable|boolean']);
        (new OrderService($this->app->db()))->note((int) $order['id'], trim($data['note']), !empty($data['to_customer']), null, $this->app->auth()->id());
        $this->notice('Note added.');
        return $this->back('/admin/orders/' . $order['id'] . '/#notes');
    }

    public function resend(Request $request): Response
    {
        $order = $this->order($request);
        $orders = new OrderService($this->app->db());
        (new Mailer())->queue($order['email'], 'Your ' . config('app.name') . ' order #' . $order['number'],
            $this->app->view()->render('emails/order-customer', ['order' => $order, 'items' => $orders->items((int) $order['id']), 'vendorOrders' => $orders->vendorOrders((int) $order['id'])]));
        Audit::log('order.email_resent', 'order', (int) $order['id']);
        $this->notice('Order confirmation re-sent to ' . $order['email'] . '.');
        return $this->back('/admin/orders/' . $order['id'] . '/');
    }

    private function filters(Request $request): array
    {
        $status = $request->query('status');
        $status = is_string($status) && isset(self::STATUSES[$status]) ? $status : null;
        $search = trim((string) $request->query('s', ''));
        $where = '1 = 1';
        $params = [];
        if ($status) {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= " AND (o.number = ? OR o.email LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(o.billing_address, '$.last_name')) LIKE ?)";
            array_push($params, ltrim($search, '#'), self::like($search), self::like($search));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('from', ''))) {
            $where .= ' AND o.created_at >= ?';
            $params[] = $request->query('from') . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('to', ''))) {
            $where .= ' AND o.created_at <= ?';
            $params[] = $request->query('to') . ' 23:59:59';
        }
        return [$where, $params, $status, $search];
    }

    private function order(Request $request): array
    {
        return $this->app->db()->first('SELECT * FROM orders WHERE id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
    }
}
