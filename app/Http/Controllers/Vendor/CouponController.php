<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Services\Vendor\VendorProducts;

/** Store coupons: funded by the vendor and only valid on the vendor's own products. */
final class CouponController extends VendorController
{
    public function index(Request $request): Response
    {
        $coupons = $this->app->db()->select('SELECT * FROM coupons WHERE vendor_id = ? ORDER BY status, created_at DESC', [$this->vendorId($request)]);
        return $this->panel($request, 'vendor/coupons', ['title' => 'Coupons', 'tab' => 'coupons', 'coupons' => $coupons]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->coupon($request));
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, $this->coupon($request));
    }

    public function delete(Request $request): Response
    {
        $coupon = $this->coupon($request);
        $db = $this->app->db();
        if ((int) $coupon['usage_count'] > 0) {
            $db->update('coupons', ['status' => 'disabled'], ['id' => (int) $coupon['id']]); // keep history for past orders
            $this->notice('Coupon disabled. It has been used before, so it is kept for your records.');
        } else {
            $db->delete('coupons', ['id' => (int) $coupon['id']]);
            $this->notice('Coupon deleted.');
        }
        return $this->back('/dashboard/coupons/');
    }

    private function coupon(Request $request): array
    {
        $coupon = $this->app->db()->first('SELECT * FROM coupons WHERE id = ? AND vendor_id = ?', [(int) $request->param('id'), $this->vendorId($request)]);
        if ($coupon === null) {
            throw new HttpException(404);
        }
        return $coupon;
    }

    private function form(Request $request, ?array $coupon): Response
    {
        $products = $this->app->db()->select("SELECT id, name FROM products WHERE vendor_id = ? AND status <> 'archived' ORDER BY name", [$this->vendorId($request)]);
        return $this->panel($request, 'vendor/coupon-edit', [
            'title' => $coupon ? 'Edit coupon' : 'Add coupon',
            'tab' => 'coupons',
            'back' => ['Coupons', '/dashboard/coupons/'],
            'coupon' => $coupon,
            'products' => $products,
            'selected' => $coupon ? array_map('intval', json_decode((string) $coupon['product_ids'], true) ?: []) : [],
        ]);
    }

    private function save(Request $request, ?array $coupon): Response
    {
        $data = $this->validate($request, [
            'code' => 'required|string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/',
            'description' => 'nullable|string|max:255',
            'type' => 'required|in:percent,fixed_cart,fixed_product',
            'amount' => 'required|numeric|min:0.01|max:100000',
            'minimum' => 'nullable|numeric|min:0|max:100000',
            'usage_limit' => 'nullable|integer|min:1|max:1000000',
            'usage_limit_per_user' => 'nullable|integer|min:1|max:1000',
            'expires_at' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}$/',
            'individual_use' => 'nullable|boolean',
            'exclude_sale_items' => 'nullable|boolean',
            'free_shipping' => 'nullable|boolean',
            'status' => 'required|in:active,disabled',
        ]);
        $vendorId = $this->vendorId($request);
        $db = $this->app->db();
        if ($data['type'] === 'percent' && (float) $data['amount'] > 100) {
            throw new ValidationException(['amount' => 'A percentage discount cannot be more than 100.'], $request);
        }
        // Codes are global and case-insensitive (the collation handles case).
        if ($db->scalar('SELECT 1 FROM coupons WHERE code = ? AND id <> ?', [$data['code'], $coupon ? (int) $coupon['id'] : 0])) {
            throw new ValidationException(['code' => 'This code is already taken. Try another one.'], $request);
        }
        $productIds = array_values(array_unique(array_filter(array_map('intval', (array) $request->post('product_ids', [])))));
        if ($productIds) {
            $productIds = array_map('intval', array_column($db->select('SELECT id FROM products WHERE vendor_id = ? AND id IN (' . implode(',', $productIds) . ')', [$vendorId]), 'id'));
        }
        $row = [
            'code' => $data['code'],
            'description' => ($t = trim((string) ($data['description'] ?? ''))) !== '' ? $t : null,
            'type' => $data['type'],
            'amount' => round((float) $data['amount'], 2),
            'vendor_id' => $vendorId,
            'cost_bearer' => 'vendor',
            'minimum_cents' => ($m = VendorProducts::parseMoney($data['minimum'] ?? '')) ? $m : null,
            'usage_limit' => $data['usage_limit'] ?? null ?: null,
            'usage_limit_per_user' => $data['usage_limit_per_user'] ?? null ?: null,
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] . ' 23:59:59' : null,
            'individual_use' => !empty($data['individual_use']) ? 1 : 0,
            'exclude_sale_items' => !empty($data['exclude_sale_items']) ? 1 : 0,
            'free_shipping' => !empty($data['free_shipping']) ? 1 : 0,
            'product_ids' => $productIds ? json_encode($productIds) : null,
            'status' => $data['status'],
        ];
        if ($coupon) {
            $db->update('coupons', $row, ['id' => (int) $coupon['id'], 'vendor_id' => $vendorId]);
        } else {
            $db->insert('coupons', $row);
        }
        $this->notice('Coupon ' . $row['code'] . ' saved.');
        return $this->back('/dashboard/coupons/');
    }
}
