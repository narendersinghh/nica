<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Referrals\Referrals;
use App\Services\Settings;
use App\Services\Vendor\Earnings;

final class StylistsController extends AdminController
{
    public const STATUSES = ['pending' => 'Awaiting approval', 'approved' => 'Approved', 'suspended' => 'Suspended', 'rejected' => 'Rejected'];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $status = $request->query('status');
        $status = is_string($status) && isset(self::STATUSES[$status]) ? $status : null;
        $search = trim((string) $request->query('s', ''));
        $where = '1 = 1';
        $params = [];
        if ($status) {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= ' AND (s.code LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.salon_name LIKE ?)';
            array_push($params, ...array_fill(0, 5, self::like($search)));
        }
        $rows = $db->select(
            "SELECT s.*, u.email, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM referral_clicks c WHERE c.stylist_id = s.id AND c.created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS clicks,
                    (SELECT COUNT(*) FROM referrals r WHERE r.stylist_id = s.id AND r.status <> 'void') AS orders,
                    (SELECT COALESCE(SUM(base_cents), 0) FROM referrals r WHERE r.stylist_id = s.id AND r.status <> 'void') AS sales,
                    (SELECT COALESCE(SUM(amount_cents), 0) FROM stylist_ledger l WHERE l.stylist_id = s.id AND l.available_at <= UTC_TIMESTAMP()) AS balance
             FROM stylists s JOIN users u ON u.id = s.user_id WHERE {$where}
             ORDER BY FIELD(s.status, 'pending', 'approved', 'suspended', 'rejected'), sales DESC, s.created_at DESC LIMIT 300",
            $params
        );
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($db->select('SELECT status, COUNT(*) n FROM stylists GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $this->panel($request, 'admin/stylists', ['title' => 'Stylists', 'tab' => 'stylists', 'rows' => $rows, 'counts' => $counts, 'status' => $status, 'search' => $search]);
    }

    public function show(Request $request): Response
    {
        $s = $this->stylist($request);
        $db = $this->app->db();
        $referrals = new Referrals($db);
        $earnings = new Earnings($db, Earnings::STYLIST);
        return $this->panel($request, 'admin/stylist', [
            'title' => trim($s['first_name'] . ' ' . $s['last_name']) ?: $s['email'],
            'tab' => 'stylists',
            'back' => ['Stylists', '/admin/stylists/'],
            's' => $s,
            'tier' => $referrals->tier($s),
            'tiers' => (array) Settings::get('referrals.tiers', []),
            'stats' => $referrals->stats((int) $s['id']),
            'balance' => $earnings->summary((int) $s['id']),
            'payoutMethod' => Earnings::mask($s['payout_method'], $earnings->details($s)),
            'ledger' => $earnings->ledger((int) $s['id'], $this->page($request), 30),
            'referrals' => $db->select('SELECT r.*, o.number, o.email FROM referrals r JOIN orders o ON o.id = r.order_id WHERE r.stylist_id = ? ORDER BY r.created_at DESC LIMIT 20', [$s['id']]),
            'sources' => $db->select('SELECT COALESCE(referrer_host, "(direct / app)") host, COUNT(*) n FROM referral_clicks WHERE stylist_id = ? GROUP BY host ORDER BY n DESC LIMIT 6', [$s['id']]),
            'defaultThreshold' => (int) Settings::get('referrals.payout_threshold_cents', 2500),
        ]);
    }

    public function update(Request $request): Response
    {
        $s = $this->editable($request);
        $tierKeys = array_column((array) Settings::get('referrals.tiers', []), 'key');
        $data = $this->validate($request, ['tier_override' => 'nullable|in:' . implode(',', $tierKeys), 'payout_threshold' => 'nullable|numeric|min:0|max:100000', 'admin_note' => 'nullable|string|max:500']);
        $update = [
            'tier_override' => ($data['tier_override'] ?? '') !== '' ? $data['tier_override'] : null,
            'payout_threshold_cents' => ($data['payout_threshold'] ?? '') !== '' && $data['payout_threshold'] !== null ? (int) round((float) $data['payout_threshold'] * 100) : null,
            'admin_note' => ($t = trim((string) ($data['admin_note'] ?? ''))) !== '' ? $t : null,
        ];
        $this->app->db()->update('stylists', $update, ['id' => (int) $s['id']]);
        Audit::log('stylist.updated', 'stylist', (int) $s['id'], $update);
        $this->notice('Stylist saved.');
        return $this->back('/admin/stylists/' . $s['id'] . '/');
    }

    /** approve | suspend | reject | reactivate */
    public function status(Request $request): Response
    {
        $s = $this->editable($request);
        $action = (string) $request->param('action');
        $to = ['approve' => 'approved', 'reactivate' => 'approved', 'suspend' => 'suspended', 'reject' => 'rejected'][$action] ?? throw new HttpException(404);
        $note = trim((string) $request->post('reason', ''));
        $db = $this->app->db();
        $db->update('stylists', ['status' => $to] + ($to === 'approved' && !$s['approved_at'] ? ['approved_at' => gmdate('Y-m-d H:i:s')] : []), ['id' => (int) $s['id']]);
        if ($to === 'approved') {
            $db->query("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'stylist')", [$s['user_id']]);
        } else {
            $db->delete('user_roles', ['user_id' => (int) $s['user_id'], 'role' => 'stylist']);
        }
        Audit::log('stylist.' . $action, 'stylist', (int) $s['id'], array_filter(['reason' => $note]));
        if ($action !== 'reactivate') {
            (new Mailer())->queue($s['email'], '[' . config('app.name') . '] ' . ($to === 'approved' ? 'Your stylist account is approved' : 'Your stylist account'),
                $this->app->view()->render('emails/stylist-status', ['stylist' => $s, 'approved' => $to === 'approved', 'status' => $to, 'note' => $note]));
        }
        $this->notice('Stylist ' . strtolower(self::STATUSES[$to]) . '.');
        return $this->back('/admin/stylists/' . $s['id'] . '/');
    }

    public function adjust(Request $request): Response
    {
        $this->authorize('finance');
        $s = $this->editable($request);
        $data = $this->validate($request, ['amount' => 'required|regex:/^-?\d{1,7}(\.\d{1,2})?$/', 'description' => 'required|string|min:3|max:255']);
        $cents = (int) round((float) $data['amount'] * 100);
        try {
            (new Earnings($this->app->db(), Earnings::STYLIST))->adjust((int) $s['id'], $cents, trim($data['description']), $this->app->auth()->id());
            Audit::log('stylist_ledger.adjusted', 'stylist', (int) $s['id'], ['amount_cents' => $cents, 'description' => $data['description']]);
            $this->notice('Adjustment of ' . money($cents) . ' added.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/admin/stylists/' . $s['id'] . '/');
    }

    private function stylist(Request $request): array
    {
        return $this->app->db()->first('SELECT s.*, u.email, u.first_name, u.last_name, u.phone FROM stylists s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
    }

    /** Stylist approval, tier and payouts are about money: never on one's own account. */
    private function editable(Request $request): array
    {
        $s = $this->stylist($request);
        if ((int) $s['user_id'] === $this->app->auth()->id()) {
            throw new HttpException(403);
        }
        return $s;
    }
}
