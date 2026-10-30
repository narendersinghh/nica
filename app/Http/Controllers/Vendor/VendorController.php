<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PanelHelpers;

/** Base for /dashboard/ pages. The VendorAccess middleware has loaded the store. */
abstract class VendorController extends Controller
{
    use PanelHelpers;

    protected function vendor(Request $request): array
    {
        $vendor = $request->attribute('vendor');
        if (!is_array($vendor)) {
            throw new HttpException(404);
        }
        return $vendor;
    }

    protected function vendorId(Request $request): int
    {
        return (int) $this->vendor($request)['id'];
    }

    protected function panel(Request $request, string $view, array $data = []): Response
    {
        $vendorId = $this->vendorId($request);
        $db = $this->app->db();
        $badges = [
            'orders' => (int) $db->scalar("SELECT COUNT(*) FROM vendor_orders WHERE vendor_id = ? AND status = 'processing'", [$vendorId]),
            'reviews' => (int) $db->scalar("SELECT COUNT(*) FROM reviews WHERE vendor_id = ? AND status = 'approved' AND vendor_reply IS NULL AND created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY", [$vendorId]),
        ];
        $response = $this->view($view, $data + [
            'vendor' => $this->vendor($request),
            'badges' => $badges,
            'errors' => $this->app->session()->getFlash('_errors', []),
        ]);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }
}
