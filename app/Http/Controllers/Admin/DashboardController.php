<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class DashboardController extends AdminController
{
    private const PAID = "('processing','on-hold','completed')";

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $month = gmdate('Y-m-01 00:00:00');
        $last = gmdate('Y-m-01 00:00:00', strtotime('first day of last month'));
        $kpi = $db->first(
            'SELECT COALESCE(SUM(CASE WHEN created_at >= ? THEN total_cents - refunded_cents END), 0) gmv,
                    COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN total_cents - refunded_cents END), 0) gmv_last,
                    COUNT(CASE WHEN created_at >= ? THEN 1 END) orders,
                    COUNT(CASE WHEN created_at >= ? AND created_at < ? THEN 1 END) orders_last
             FROM orders WHERE status IN ' . self::PAID,
            [$month, $last, $month, $month, $last, $month]
        );
        $commission = (int) $db->scalar("SELECT COALESCE(SUM(commission_cents), 0) FROM vendor_orders WHERE created_at >= ? AND status IN ('processing','on-hold','shipped','completed')", [$month]);

        $daily = array_fill_keys(array_map(static fn ($d) => gmdate('Y-m-d', strtotime("-{$d} days")), range(29, 0)), 0);
        foreach ($db->select('SELECT DATE(created_at) d, SUM(total_cents - refunded_cents) s FROM orders WHERE status IN ' . self::PAID . ' AND created_at >= UTC_DATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)') as $r) {
            if (isset($daily[$r['d']])) {
                $daily[$r['d']] = (int) $r['s'];
            }
        }

        return $this->panel($request, 'admin/dashboard', [
            'title' => 'Dashboard',
            'tab' => 'overview',
            'kpi' => $kpi,
            'commission' => $commission,
            'daily' => $daily,
            'queues' => [
                ['Vendor applications', (int) $db->scalar("SELECT COUNT(*) FROM vendors WHERE status = 'pending'"), '/admin/vendors/?status=pending', 'store'],
                ['Products to review', (int) $db->scalar("SELECT COUNT(*) FROM products WHERE status = 'pending'"), '/admin/products/?status=pending', 'box'],
                ['Orders on hold', (int) $db->scalar("SELECT COUNT(*) FROM orders WHERE status = 'on-hold'"), '/admin/orders/?status=on-hold', 'alert'],
                ['Stylist applications', (int) $db->scalar("SELECT COUNT(*) FROM stylists WHERE status = 'pending'"), '/admin/stylists/?status=pending', 'users'],
                ['Payout requests', (int) $db->scalar("SELECT (SELECT COUNT(*) FROM payout_requests WHERE status IN ('pending','approved')) + (SELECT COUNT(*) FROM stylist_payout_requests WHERE status IN ('pending','approved'))"), '/admin/payouts/', 'wallet'],
                ['Reviews to moderate', (int) $db->scalar("SELECT COUNT(*) FROM reviews WHERE status = 'pending'"), '/admin/reviews/?status=pending', 'star'],
                ['Orders not shipped after 3 days', (int) $db->scalar("SELECT COUNT(*) FROM vendor_orders WHERE status = 'processing' AND created_at < UTC_TIMESTAMP() - INTERVAL 3 DAY"), '/admin/orders/?status=processing', 'truck'],
            ],
            'payoutTotal' => (int) $db->scalar("SELECT (SELECT COALESCE(SUM(amount_cents), 0) FROM payout_requests WHERE status IN ('pending','approved')) + (SELECT COALESCE(SUM(amount_cents), 0) FROM stylist_payout_requests WHERE status IN ('pending','approved'))"),
            'recent' => $db->select('SELECT id, number, email, status, total_cents, billing_address, created_at FROM orders ORDER BY created_at DESC LIMIT 8'),
            'topVendors' => $db->select(
                "SELECT v.id, v.store_name, SUM(vo.subtotal_cents - vo.discount_cents) sales, COUNT(*) orders
                 FROM vendor_orders vo JOIN vendors v ON v.id = vo.vendor_id
                 WHERE vo.status IN ('processing','on-hold','shipped','completed') AND vo.created_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY
                 GROUP BY v.id, v.store_name ORDER BY sales DESC LIMIT 5"
            ),
            'lowStock' => (int) $db->scalar("SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.status = 'published' AND v.is_active = 1 AND v.manage_stock = 1 AND v.stock_qty <= 3"),
            'needs2fa' => !$this->app->auth()->user()['two_factor_enabled_at'],
        ]);
    }
}
