<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorProducts;

final class PayoutsController extends AdminController
{
    public const STATUSES = ['open' => 'To pay', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $status = (string) $request->query('status', 'open');
        $status = isset(self::STATUSES[$status]) ? $status : 'open';
        $where = $status === 'open' ? "r.status IN ('pending','approved')" : 'r.status = ?';
        $rows = $db->select(
            "SELECT * FROM (
                SELECT 'vendor' AS owner, r.id, r.vendor_id AS owner_id, r.amount_cents, r.method, r.status, r.created_at, v.store_name AS owner_name,
                       (SELECT COALESCE(SUM(amount_cents), 0) FROM vendor_ledger l WHERE l.vendor_id = r.vendor_id AND l.available_at <= UTC_TIMESTAMP()) AS balance
                FROM payout_requests r JOIN vendors v ON v.id = r.vendor_id WHERE {$where}
                UNION ALL
                SELECT 'stylist', r.id, r.stylist_id, r.amount_cents, r.method, r.status, r.created_at, CONCAT(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ' (', s.code, ')'),
                       (SELECT COALESCE(SUM(amount_cents), 0) FROM stylist_ledger l WHERE l.stylist_id = r.stylist_id AND l.available_at <= UTC_TIMESTAMP())
                FROM stylist_payout_requests r JOIN stylists s ON s.id = r.stylist_id JOIN users u ON u.id = s.user_id WHERE {$where}
             ) q ORDER BY created_at " . ($status === 'open' ? 'ASC' : 'DESC') . ' LIMIT 200',
            $status === 'open' ? [] : [$status, $status]
        );
        $balances = $db->select(
            "SELECT v.id, v.store_name, v.payout_method, v.stripe_charges_enabled, COALESCE(SUM(l.amount_cents), 0) AS available
             FROM vendors v JOIN vendor_ledger l ON l.vendor_id = v.id AND l.available_at <= UTC_TIMESTAMP()
             GROUP BY v.id, v.store_name, v.payout_method, v.stripe_charges_enabled HAVING available <> 0 ORDER BY available DESC"
        );
        return $this->panel($request, 'admin/payouts', ['title' => 'Payouts', 'tab' => 'payouts', 'rows' => $rows, 'status' => $status, 'balances' => $balances]);
    }

    public function show(Request $request): Response
    {
        $owner = $this->owner($request);
        $req = $this->request($request);
        $earnings = new Earnings($this->app->db(), $owner);
        Audit::log('payout.details_viewed', $owner === Earnings::STYLIST ? 'stylist_payout' : 'payout_request', (int) $req['id']);
        $vendor = $owner === Earnings::STYLIST
            ? $this->app->db()->first("SELECT s.*, CONCAT(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ' (', s.code, ')') AS store_name FROM stylists s JOIN users u ON u.id = s.user_id WHERE s.id = ?", [$req['stylist_id']])
            : $this->app->db()->first('SELECT * FROM vendors WHERE id = ?', [$req['vendor_id']]);
        return $this->panel($request, 'admin/payout', [
            'owner' => $owner,
            'base' => $owner === Earnings::STYLIST ? '/admin/payouts/stylist/' . $req['id'] . '/' : '/admin/payouts/' . $req['id'] . '/',
            'ledgerUrl' => $owner === Earnings::STYLIST ? '/admin/stylists/' . $req['stylist_id'] . '/' : '/admin/payouts/ledger/' . $req['vendor_id'] . '/',
            'title' => 'Payout request #' . $req['id'],
            'tab' => 'payouts',
            'back' => ['Payouts', '/admin/payouts/'],
            'req' => $req,
            'v' => $vendor,
            'details' => $earnings->requestDetails($req),
            'balance' => $earnings->summary((int) $req[$owner === Earnings::STYLIST ? 'stylist_id' : 'vendor_id']),
        ]);
    }

    public function pay(Request $request): Response
    {
        $owner = $this->owner($request);
        $req = $this->request($request);
        $data = $this->validate($request, ['reference' => 'required|string|max:100']);
        try {
            (new Earnings($this->app->db(), $owner))->markPaid((int) $req['id'], $this->app->auth()->id(), trim($data['reference']));
            Audit::log('payout.paid', $owner === Earnings::STYLIST ? 'stylist_payout' : 'payout_request', (int) $req['id'], ['amount_cents' => (int) $req['amount_cents'], 'reference' => $data['reference']]);
            $this->mailVendor($req, 'paid', trim($data['reference']));
            $this->notice('Marked as paid. The vendor has been notified.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/admin/payouts/');
    }

    public function reject(Request $request): Response
    {
        $owner = $this->owner($request);
        $req = $this->request($request);
        $data = $this->validate($request, ['note' => 'required|string|max:500']);
        if ((new Earnings($this->app->db(), $owner))->reject((int) $req['id'], $this->app->auth()->id(), trim($data['note']))) {
            Audit::log('payout.rejected', $owner === Earnings::STYLIST ? 'stylist_payout' : 'payout_request', (int) $req['id'], ['note' => $data['note']]);
            $this->mailVendor($req, 'rejected', trim($data['note']));
            $this->notice('Request rejected. The money is back in the vendor\'s available balance.');
        }
        return $this->back('/admin/payouts/');
    }

    public function ledger(Request $request): Response
    {
        $vendor = $this->app->db()->first('SELECT * FROM vendors WHERE id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
        $earnings = new Earnings($this->app->db());
        return $this->panel($request, 'admin/ledger', [
            'title' => 'Ledger · ' . $vendor['store_name'],
            'tab' => 'payouts',
            'back' => [$vendor['store_name'], '/admin/vendors/' . $vendor['id'] . '/'],
            'v' => $vendor,
            'ledger' => $earnings->ledger((int) $vendor['id'], $this->page($request), 50),
            'balance' => $earnings->summary((int) $vendor['id']),
        ]);
    }

    public function adjust(Request $request): Response
    {
        $vendorId = (int) $request->param('id');
        $data = $this->validate($request, ['amount' => 'required|regex:/^-?\d{1,7}(\.\d{1,2})?$/', 'description' => 'required|string|min:3|max:255']);
        $cents = (int) round((float) $data['amount'] * 100);
        try {
            (new Earnings($this->app->db()))->adjust($vendorId, $cents, trim($data['description']), $this->app->auth()->id());
            Audit::log('ledger.adjusted', 'vendor', $vendorId, ['amount_cents' => $cents, 'description' => $data['description']]);
            $this->notice('Adjustment of ' . money($cents) . ' added.');
        } catch (\DomainException $e) {
            $this->error($e->getMessage());
        }
        return $this->back('/admin/payouts/ledger/' . $vendorId . '/');
    }

    public function export(Request $request): Response
    {
        $rows = $this->app->db()->select(
            "SELECT l.created_at, v.store_name, l.type, l.description, l.amount_cents, l.available_at, l.reference, o.number
             FROM vendor_ledger l JOIN vendors v ON v.id = l.vendor_id LEFT JOIN vendor_orders vo ON vo.id = l.vendor_order_id LEFT JOIN orders o ON o.id = vo.order_id
             ORDER BY l.created_at, l.id"
        );
        Audit::log('ledger.exported', null, null, ['rows' => count($rows)]);
        return $this->csv('vendor-ledger-' . gmdate('Y-m-d') . '.csv', ['Date (UTC)', 'Vendor', 'Type', 'Description', 'Amount', 'Available from', 'Reference', 'Order'],
            array_map(static fn ($r) => [$r['created_at'], $r['store_name'], $r['type'], $r['description'], number_format((int) $r['amount_cents'] / 100, 2, '.', ''), $r['available_at'] ?? 'pending', $r['reference'], $r['number']], $rows));
    }

    private function mailVendor(array $req, string $outcome, string $text): void
    {
        $email = isset($req['stylist_id'])
            ? $this->app->db()->scalar('SELECT u.email FROM stylists s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$req['stylist_id']])
            : $this->app->db()->scalar('SELECT u.email FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = ?', [$req['vendor_id']]);
        if ($email) {
            (new Mailer())->queue((string) $email, '[' . config('app.name') . '] Payout ' . ($outcome === 'paid' ? 'sent: ' : 'request declined: ') . money((int) $req['amount_cents']),
                $this->app->view()->render('emails/payout-status', ['req' => $req, 'outcome' => $outcome, 'text' => $text, 'stylist' => isset($req['stylist_id'])]));
        }
    }

    private function request(Request $request): array
    {
        $table = $this->owner($request) === Earnings::STYLIST ? 'stylist_payout_requests' : 'payout_requests';
        return $this->app->db()->first("SELECT * FROM {$table} WHERE id = ?", [(int) $request->param('id')]) ?? throw new HttpException(404);
    }

    /** Stylist requests live under /admin/payouts/stylist/{id}/. */
    private function owner(Request $request): string
    {
        return str_contains($request->path(), '/payouts/stylist/') ? Earnings::STYLIST : Earnings::VENDOR;
    }
}
