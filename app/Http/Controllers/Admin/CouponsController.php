<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Services\Audit;
use App\Services\Vendor\VendorProducts;

/** Every coupon: marketplace coupons (who pays is configurable) and vendors' own store coupons. */
final class CouponsController extends AdminController
{
    public const BEARERS = ['platform' => 'The NiCa Collection pays', 'vendor' => 'Vendors pay', 'shared' => 'Shared (in proportion to commission)'];

    public function index(Request $request): Response
    {
        $rows = $this->app->db()->select('SELECT c.*, v.store_name FROM coupons c LEFT JOIN vendors v ON v.id = c.vendor_id ORDER BY c.status, c.vendor_id IS NOT NULL, c.created_at DESC');
        return $this->panel($request, 'admin/coupons', ['title' => 'Coupons', 'tab' => 'coupons', 'rows' => $rows]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->coupon($request));
    }

    public function save(Request $request): Response
    {
        $coupon = $request->param('id') !== null ? $this->coupon($request) : null;
        $data = $this->validate($request, [
            'code' => 'required|string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/',
            'description' => 'nullable|string|max:255',
            'type' => 'required|in:percent,fixed_cart,fixed_product',
            'amount' => 'required|numeric|min:0|max:100000',
            'cost_bearer' => 'required|in:platform,vendor,shared',
            'vendor_id' => 'nullable|integer',
            'minimum' => 'nullable|string|max:12',
            'maximum' => 'nullable|string|max:12',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_limit_per_user' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}$/',
            'expires_at' => 'nullable|regex:/^\d{4}-\d{2}-\d{2}$/',
            'allowed_emails' => 'nullable|string|max:5000',
            'status' => 'required|in:active,disabled',
        ]);
        $db = $this->app->db();
        if ($data['type'] === 'percent' && (float) $data['amount'] > 100) {
            throw new ValidationException(['amount' => 'cannot be more than 100 for a percentage.'], $request);
        }
        if ($db->scalar('SELECT 1 FROM coupons WHERE code = ? AND id <> ?', [$data['code'], $coupon ? (int) $coupon['id'] : 0])) {
            throw new ValidationException(['code' => 'is already used by another coupon.'], $request);
        }
        $ids = static fn (string $table, mixed $list) => ($l = array_values(array_unique(array_filter(array_map('intval', (array) $list))))) ? array_map('intval', array_column($db->select('SELECT id FROM ' . $table . ' WHERE id IN (' . implode(',', $l) . ')'), 'id')) : [];
        $emails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower((string) ($data['allowed_emails'] ?? ''))) ?: []), static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $vendorId = (int) ($data['vendor_id'] ?? 0) ?: null;
        if ($vendorId !== null && !$db->scalar('SELECT 1 FROM vendors WHERE id = ?', [$vendorId])) {
            $vendorId = null;
        }
        $json = static fn (array $l) => $l ? json_encode(array_values($l)) : null;
        $row = [
            'code' => $data['code'],
            'description' => ($t = trim((string) ($data['description'] ?? ''))) !== '' ? $t : null,
            'type' => $data['type'],
            'amount' => round((float) $data['amount'], 2),
            'vendor_id' => $vendorId,
            'cost_bearer' => $vendorId ? 'vendor' : $data['cost_bearer'],
            'free_shipping' => $request->post('free_shipping') ? 1 : 0,
            'individual_use' => $request->post('individual_use') ? 1 : 0,
            'exclude_sale_items' => $request->post('exclude_sale_items') ? 1 : 0,
            'minimum_cents' => VendorProducts::parseMoney($data['minimum'] ?? '') ?: null,
            'maximum_cents' => VendorProducts::parseMoney($data['maximum'] ?? '') ?: null,
            'usage_limit' => ($data['usage_limit'] ?? null) ?: null,
            'usage_limit_per_user' => ($data['usage_limit_per_user'] ?? null) ?: null,
            'starts_at' => !empty($data['starts_at']) ? $data['starts_at'] . ' 00:00:00' : null,
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] . ' 23:59:59' : null,
            'product_ids' => $json($ids('products', $request->post('product_ids', []))),
            'category_ids' => $json($ids('product_categories', $request->post('category_ids', []))),
            'vendor_ids' => $vendorId ? null : $json($ids('vendors', $request->post('vendor_ids', []))),
            'allowed_emails' => $json($emails),
            'status' => $data['status'],
        ];
        if ($coupon) {
            $db->update('coupons', $row, ['id' => (int) $coupon['id']]);
            $id = (int) $coupon['id'];
        } else {
            $id = $db->insert('coupons', $row);
        }
        Audit::log($coupon ? 'coupon.updated' : 'coupon.created', 'coupon', $id, ['code' => $row['code']]);
        $this->notice('Coupon ' . $row['code'] . ' saved.');
        return $this->back('/admin/coupons/');
    }

    public function delete(Request $request): Response
    {
        $coupon = $this->coupon($request);
        $db = $this->app->db();
        if ((int) $coupon['usage_count'] > 0) {
            $db->update('coupons', ['status' => 'disabled'], ['id' => (int) $coupon['id']]);
            $this->notice('Coupon disabled (it has been used, so it is kept for order history).');
        } else {
            $db->delete('coupons', ['id' => (int) $coupon['id']]);
            $this->notice('Coupon deleted.');
        }
        Audit::log('coupon.deleted', 'coupon', (int) $coupon['id'], ['code' => $coupon['code']]);
        return $this->back('/admin/coupons/');
    }

    private function form(Request $request, ?array $coupon): Response
    {
        $db = $this->app->db();
        $list = static fn (?string $json) => array_map('intval', json_decode((string) $json, true) ?: []);
        return $this->panel($request, 'admin/coupon', [
            'title' => $coupon ? 'Coupon ' . $coupon['code'] : 'New coupon',
            'tab' => 'coupons',
            'back' => ['Coupons', '/admin/coupons/'],
            'c' => $coupon,
            'vendors' => $db->select('SELECT id, store_name FROM vendors ORDER BY store_name'),
            'categories' => $db->select('SELECT id, name, path FROM product_categories ORDER BY path'),
            'products' => $db->select("SELECT p.id, p.name, v.store_name FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.status <> 'archived' ORDER BY v.store_name, p.name"),
            'sel' => [
                'products' => $list($coupon['product_ids'] ?? null),
                'categories' => $list($coupon['category_ids'] ?? null),
                'vendors' => $list($coupon['vendor_ids'] ?? null),
            ],
            'emails' => implode("\n", json_decode((string) ($coupon['allowed_emails'] ?? ''), true) ?: []),
        ]);
    }

    private function coupon(Request $request): array
    {
        return $this->app->db()->first('SELECT * FROM coupons WHERE id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
    }
}
