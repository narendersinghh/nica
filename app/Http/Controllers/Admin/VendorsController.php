<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Payments\StripeConnect;
use App\Services\Vendor\Earnings;

final class VendorsController extends AdminController
{
    public const STATUSES = ['pending' => 'Awaiting approval', 'approved' => 'Approved', 'suspended' => 'Suspended', 'disabled' => 'Rejected / disabled'];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $status = $request->query('status');
        $status = is_string($status) && isset(self::STATUSES[$status]) ? $status : null;
        $search = trim((string) $request->query('s', ''));
        $where = '1 = 1';
        $params = [];
        if ($status) {
            $where .= ' AND v.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= ' AND (v.store_name LIKE ? OR u.email LIKE ?)';
            array_push($params, self::like($search), self::like($search));
        }
        $vendors = $db->select(
            "SELECT v.*, u.email, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.status = 'published') AS products,
                    (SELECT COALESCE(SUM(vo.subtotal_cents - vo.discount_cents), 0) FROM vendor_orders vo WHERE vo.vendor_id = v.id AND vo.status IN ('processing','on-hold','shipped','completed')) AS sales,
                    (SELECT COALESCE(SUM(l.amount_cents), 0) FROM vendor_ledger l WHERE l.vendor_id = v.id AND l.available_at <= UTC_TIMESTAMP()) AS balance
             FROM vendors v LEFT JOIN users u ON u.id = v.user_id WHERE {$where}
             ORDER BY FIELD(v.status, 'pending', 'approved', 'suspended', 'disabled'), v.store_name",
            $params
        );
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($db->select('SELECT status, COUNT(*) n FROM vendors GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $this->panel($request, 'admin/vendors', ['title' => 'Vendors', 'tab' => 'vendors', 'vendors' => $vendors, 'counts' => $counts, 'status' => $status, 'search' => $search]);
    }

    public function show(Request $request): Response
    {
        $vendor = $this->vendor($request);
        $db = $this->app->db();
        $earnings = new Earnings($db);
        return $this->panel($request, 'admin/vendor', [
            'title' => $vendor['store_name'],
            'tab' => 'vendors',
            'back' => ['Vendors', '/admin/vendors/'],
            'v' => $vendor,
            'owner' => $vendor['user_id'] ? $db->first('SELECT id, email, first_name, last_name, phone, created_at, last_login_at FROM users WHERE id = ?', [$vendor['user_id']]) : null,
            'balance' => $earnings->summary((int) $vendor['id']),
            'payoutMethod' => Earnings::mask($vendor['payout_method'], $earnings->details($vendor)),
            'connected' => StripeConnect::isConnected($vendor),
            'stats' => $db->first("SELECT COUNT(*) orders, COALESCE(SUM(subtotal_cents - discount_cents), 0) sales, COALESCE(SUM(commission_cents), 0) commission FROM vendor_orders WHERE vendor_id = ? AND status IN ('processing','on-hold','shipped','completed')", [$vendor['id']]),
            'productCount' => (int) $db->scalar("SELECT COUNT(*) FROM products WHERE vendor_id = ? AND status <> 'archived'", [$vendor['id']]),
            'defaultCommission' => (float) \App\Services\Settings::get('commerce.commission_rate', 15),
            'defaultThreshold' => (int) \App\Services\Settings::get('commerce.payout_threshold_cents', 5000),
            'canEdit' => \App\Http\Middleware\AdminAccess::can('vendors'),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->authorize('vendors');
        $vendor = $this->vendor($request);
        $data = $this->validate($request, [
            'store_name' => 'required|string|min:2|max:100',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'payout_threshold' => 'nullable|numeric|min:0|max:100000',
            'nica_score' => 'nullable|numeric|min:0|max:10',
            'is_featured' => 'nullable|boolean',
            'is_premier_partner' => 'nullable|boolean',
            'premier_number' => 'nullable|integer|min:1|max:999',
        ]);
        $premier = !empty($data['is_premier_partner']);
        $number = $premier ? ($data['premier_number'] ?? null) : null;
        if ($premier && $number === null) {
            $number = (int) $this->app->db()->scalar('SELECT COALESCE(MAX(premier_number), 0) + 1 FROM vendors');
        }
        if ($number !== null && $this->app->db()->scalar('SELECT 1 FROM vendors WHERE premier_number = ? AND id <> ?', [$number, $vendor['id']])) {
            throw new ValidationException(['premier_number' => 'is already used by another partner.'], $request);
        }
        $update = [
            'store_name' => trim($data['store_name']),
            'commission_rate' => ($data['commission_rate'] ?? '') !== '' && $data['commission_rate'] !== null ? round((float) $data['commission_rate'], 2) : null,
            'payout_threshold_cents' => ($data['payout_threshold'] ?? '') !== '' && $data['payout_threshold'] !== null ? (int) round((float) $data['payout_threshold'] * 100) : null,
            'nica_score' => ($data['nica_score'] ?? '') !== '' && $data['nica_score'] !== null ? round((float) $data['nica_score'], 1) : null,
            'is_featured' => !empty($data['is_featured']) ? 1 : 0,
            'is_premier_partner' => $premier ? 1 : 0,
            'premier_number' => $number,
        ];
        $changes = array_filter($update, static fn ($value, $key) => (string) $vendor[$key] !== (string) $value, ARRAY_FILTER_USE_BOTH);
        $this->app->db()->update('vendors', $update, ['id' => (int) $vendor['id']]);
        if ($changes) {
            Audit::log('vendor.updated', 'vendor', (int) $vendor['id'], ['changes' => $changes]);
        }
        $this->notice('Vendor saved.');
        return $this->back('/admin/vendors/' . $vendor['id'] . '/');
    }

    /** approve | suspend | reject | reactivate */
    public function status(Request $request): Response
    {
        $this->authorize('vendors');
        $vendor = $this->vendor($request);
        $action = (string) $request->param('action');
        $reason = trim((string) $request->post('reason', ''));
        $db = $this->app->db();
        $to = match ($action) {
            'approve', 'reactivate' => 'approved',
            'suspend' => 'suspended',
            'reject' => 'disabled',
            default => throw new HttpException(404),
        };
        $update = ['status' => $to];
        if ($to === 'approved' && !$vendor['approved_at']) {
            $update['approved_at'] = gmdate('Y-m-d H:i:s');
            // The first 50 approved stores become numbered Premier Partners (live-site rule).
            $partners = (int) $db->scalar('SELECT COUNT(*) FROM vendors WHERE is_premier_partner = 1');
            if (!(int) $vendor['is_premier_partner'] && $partners < 50) {
                $update['is_premier_partner'] = 1;
                $update['premier_number'] = (int) $db->scalar('SELECT COALESCE(MAX(premier_number), 0) + 1 FROM vendors');
            }
        }
        $db->update('vendors', $update, ['id' => (int) $vendor['id']]);
        if ($to === 'approved' && $vendor['user_id']) {
            $db->query("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'vendor')", [$vendor['user_id']]);
        }
        Audit::log('vendor.' . $action, 'vendor', (int) $vendor['id'], array_filter(['from' => $vendor['status'], 'reason' => $reason]));

        $email = $vendor['user_id'] ? $db->scalar('SELECT email FROM users WHERE id = ?', [$vendor['user_id']]) : null;
        if ($email && in_array($action, ['approve', 'reject', 'suspend'], true)) {
            $subjects = ['approve' => 'Your store is approved', 'reject' => 'About your store application', 'suspend' => 'Your store has been suspended'];
            (new Mailer())->queue((string) $email, '[' . config('app.name') . '] ' . $subjects[$action],
                $this->app->view()->render('emails/vendor-status', ['vendor' => $vendor, 'action' => $action, 'reason' => $reason]));
        }
        $this->notice($vendor['store_name'] . ': ' . strtolower(self::STATUSES[$to]) . '.');
        return $this->back('/admin/vendors/' . $vendor['id'] . '/');
    }

    /** Support: open the vendor dashboard as this vendor (recorded in the audit log). */
    public function impersonate(Request $request): Response
    {
        $this->authorize('vendors');
        $vendor = $this->vendor($request);
        $user = $vendor['user_id'] ? $this->app->db()->first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$vendor['user_id']]) : null;
        if ($user === null) {
            $this->error('This store has no active owner account.');
            return $this->back('/admin/vendors/' . $vendor['id'] . '/');
        }
        try {
            Audit::log('vendor.impersonate', 'vendor', (int) $vendor['id'], ['user_id' => (int) $user['id']]);
            $this->app->auth()->impersonate($user);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
            return $this->back('/admin/vendors/' . $vendor['id'] . '/');
        }
        return $this->back('/dashboard/');
    }

    public function stopImpersonating(Request $request): Response
    {
        $target = $this->app->auth()->id();
        if ($this->app->auth()->stopImpersonating() === null) {
            return $this->back('/my-account/');
        }
        Audit::log('vendor.impersonate_end', 'user', $target);
        return $this->back('/admin/vendors/');
    }

    private function vendor(Request $request): array
    {
        $vendor = $this->app->db()->first('SELECT * FROM vendors WHERE id = ?', [(int) $request->param('id')]);
        return $vendor ?? throw new HttpException(404);
    }
}
