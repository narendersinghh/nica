<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Payments\StripeConnect;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorProducts;

final class DashboardController extends VendorController
{
    private const PAID = "('processing','on-hold','shipped','completed')";

    public function overview(Request $request): Response
    {
        $vendor = $this->vendor($request);
        $id = (int) $vendor['id'];
        $db = $this->app->db();

        $month = gmdate('Y-m-01 00:00:00');
        $lastMonth = gmdate('Y-m-01 00:00:00', strtotime('first day of last month'));
        $sales = $db->first(
            'SELECT COALESCE(SUM(CASE WHEN vo.created_at >= ? THEN vo.subtotal_cents - vo.discount_cents END), 0) AS this_month,
                    COALESCE(SUM(CASE WHEN vo.created_at >= ? AND vo.created_at < ? THEN vo.subtotal_cents - vo.discount_cents END), 0) AS last_month,
                    COUNT(CASE WHEN vo.created_at >= ? THEN 1 END) AS orders_month,
                    COALESCE(SUM(CASE WHEN vo.created_at >= ? THEN vo.vendor_earning_cents END), 0) AS earned_month,
                    COUNT(*) AS orders_total
             FROM vendor_orders vo WHERE vo.vendor_id = ? AND vo.status IN ' . self::PAID,
            [$month, $lastMonth, $month, $month, $month, $id]
        );

        // Daily sales for the last 30 days (for the bar chart).
        $daily = array_fill_keys(array_map(static fn ($d) => gmdate('Y-m-d', strtotime("-{$d} days")), range(29, 0)), 0);
        foreach ($db->select('SELECT DATE(created_at) d, SUM(subtotal_cents - discount_cents) s FROM vendor_orders WHERE vendor_id = ? AND status IN ' . self::PAID . ' AND created_at >= UTC_DATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)', [$id]) as $r) {
            if (isset($daily[$r['d']])) {
                $daily[$r['d']] = (int) $r['s'];
            }
        }

        $products = new VendorProducts($db);
        $earnings = new Earnings($db);
        $productCounts = $products->counts($id);
        $details = $earnings->details($vendor);

        $checklist = [
            ['Add your store logo and banner', $vendor['logo_media_id'] && $vendor['banner_media_id'], '/dashboard/settings/store/'],
            ['Write a short "about" for your store', trim((string) $vendor['about']) !== '', '/dashboard/settings/store/'],
            ['Set up how you get paid', StripeConnect::isConnected($vendor) || $details !== null, '/dashboard/settings/payment/'],
            ['Add your first product', array_sum($productCounts) > 0, '/dashboard/products/new/'],
            ['Store approved by The NiCa Collection', $vendor['status'] === 'approved', null],
        ];

        return $this->panel($request, 'vendor/overview', [
            'title' => 'Overview',
            'tab' => 'overview',
            'sales' => $sales,
            'daily' => $daily,
            'balance' => $earnings->summary($id),
            'threshold' => $earnings->threshold($vendor),
            'productCounts' => $productCounts,
            'lowStock' => $products->lowStock($id),
            'recent' => $db->select(
                'SELECT vo.id, vo.status, vo.total_cents, vo.vendor_earning_cents, o.number, o.billing_address, o.created_at
                 FROM vendor_orders vo JOIN orders o ON o.id = vo.order_id WHERE vo.vendor_id = ? ORDER BY o.created_at DESC LIMIT 6',
                [$id]
            ),
            'toShip' => (int) $db->scalar("SELECT COUNT(*) FROM vendor_orders WHERE vendor_id = ? AND status = 'processing'", [$id]),
            'checklist' => $checklist,
            'connected' => StripeConnect::isConnected($vendor),
        ]);
    }
}
