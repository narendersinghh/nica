<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Referrals\Referrals;
use App\Services\Seo;
use App\Services\Settings;
use App\Services\Vendor\Earnings;
use App\Services\Vendor\VendorProducts;

/** /my-account/stylist/: apply to the referral program, then share your link and track earnings. */
final class StylistController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user();
        $db = $this->app->db();
        $stylist = $db->first('SELECT * FROM stylists WHERE user_id = ?', [$user['id']]);
        $referrals = new Referrals($db);
        if ($stylist === null || $stylist['status'] !== 'approved') {
            return $this->page('account/stylist-join', [
                'stylist' => $stylist,
                'suggested' => $referrals->suggestCode($user['first_name'] ?: (string) strstr($user['email'], '@', true)),
                'tiers' => (array) Settings::get('referrals.tiers', []),
                'enabled' => Referrals::enabled(),
            ]);
        }
        $earnings = new Earnings($db, Earnings::STYLIST);
        return $this->page('account/stylist', [
            'stylist' => $stylist,
            'link' => $this->app->url()->to('/', ['ref' => $stylist['code']]),
            'tier' => $referrals->tier($stylist),
            'tiers' => (array) Settings::get('referrals.tiers', []),
            'stats' => $referrals->stats((int) $stylist['id']),
            'balance' => $earnings->summary((int) $stylist['id']),
            'threshold' => $earnings->threshold($stylist),
            'ledger' => $earnings->ledger((int) $stylist['id'], 1, 15)['rows'],
            'requests' => $earnings->requests((int) $stylist['id'], 10),
            'details' => $earnings->details($stylist) ?? [],
            'method' => Earnings::mask($stylist['payout_method'], $earnings->details($stylist)),
            'recent' => $db->select(
                "SELECT r.*, o.number, o.created_at AS ordered_at, o.status AS order_status FROM referrals r JOIN orders o ON o.id = r.order_id WHERE r.stylist_id = ? ORDER BY r.created_at DESC LIMIT 10",
                [$stylist['id']]
            ),
            'windowDays' => (int) Settings::get('referrals.tier_window_days', 90),
        ]);
    }

    public function join(Request $request): Response
    {
        $user = $this->user();
        $db = $this->app->db();
        if (!Referrals::enabled() || $db->scalar('SELECT 1 FROM stylists WHERE user_id = ?', [$user['id']])) {
            return $this->redirect(link_to('/my-account/stylist/'), 303);
        }
        $data = $this->validate($request, [
            'code' => 'required|string|min:4|max:20|regex:/^[A-Za-z0-9]+$/',
            'salon_name' => 'nullable|string|max:150',
            'instagram' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'state' => 'required|in:' . implode(',', array_keys(config('us_states'))),
            'about' => 'nullable|string|max:1000',
            'terms' => 'required|boolean',
        ]);
        $code = Referrals::normalize($data['code']);
        if (in_array($code, ['NICA', 'ADMIN', 'TEST', 'STYLIST'], true) || $db->scalar('SELECT 1 FROM stylists WHERE code = ?', [$code]) || $db->scalar('SELECT 1 FROM coupons WHERE code = ?', [$code])) {
            throw new ValidationException(['code' => 'is taken. Try another one.'], $request);
        }
        $auto = (bool) Settings::get('referrals.auto_approve', false);
        $id = $db->insert('stylists', [
            'user_id' => (int) $user['id'],
            'code' => $code,
            'status' => $auto ? 'approved' : 'pending',
            'salon_name' => ($t = trim((string) ($data['salon_name'] ?? ''))) !== '' ? $t : null,
            'instagram' => ($t = ltrim(trim((string) ($data['instagram'] ?? '')), '@')) !== '' ? mb_substr_safe($t, 100) : null,
            'city' => trim($data['city']),
            'state' => $data['state'],
            'about' => ($t = trim((string) ($data['about'] ?? ''))) !== '' ? $t : null,
            'approved_at' => $auto ? gmdate('Y-m-d H:i:s') : null,
        ]);
        if ($auto) {
            $db->query("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'stylist')", [$user['id']]);
        }
        Audit::log('stylist.applied', 'stylist', $id, ['code' => $code]);
        (new Mailer())->queue((string) Settings::get('site.contact.email'), '[' . config('app.name') . '] New stylist application: ' . trim($user['first_name'] . ' ' . $user['last_name']),
            $this->app->view()->render('emails/stylist-application', ['user' => $user, 'code' => $code, 'data' => $data, 'auto' => $auto]));
        $this->app->session()->flash('account_notice', $auto ? 'Welcome to the stylist program! Your link is ready to share.' : 'Thanks for applying! We will email you as soon as your stylist account is approved.');
        return $this->redirect(link_to('/my-account/stylist/'), 303);
    }

    public function savePayout(Request $request): Response
    {
        $stylist = $this->approved();
        $method = (string) $request->post('method');
        if ($method === 'paypal') {
            $data = $this->validate($request, ['paypal_email' => 'required|email']);
            $details = ['email' => strtolower(trim($data['paypal_email']))];
        } elseif ($method === 'bank') {
            $data = $this->validate($request, [
                'account_name' => 'required|string|max:100', 'bank_name' => 'required|string|max:100',
                'routing_number' => 'required|regex:/^\d{9}$/', 'account_number' => 'required|regex:/^\d{4,17}$/', 'account_type' => 'required|in:checking,savings',
            ]);
            $details = array_map(static fn ($v) => trim((string) $v), $data);
        } else {
            throw new ValidationException(['method' => 'Choose PayPal or bank transfer.'], $request);
        }
        (new Earnings($this->app->db(), Earnings::STYLIST))->saveDetails((int) $stylist['id'], $method, $details);
        $this->app->session()->flash('account_notice', 'Payout details saved (stored encrypted).');
        return $this->redirect(link_to('/my-account/stylist/#payouts'), 303);
    }

    public function withdraw(Request $request): Response
    {
        $stylist = $this->approved();
        $cents = VendorProducts::parseMoney((string) $request->post('amount', ''));
        try {
            if ($cents === null) {
                throw new \DomainException('Enter an amount like 40.00.');
            }
            (new Earnings($this->app->db(), Earnings::STYLIST))->request((int) $stylist['id'], $cents, trim((string) $request->post('note', '')));
            $this->app->session()->flash('account_notice', 'Payout request for ' . money($cents) . ' sent.');
        } catch (\DomainException $e) {
            $this->app->session()->flash('_errors', ['amount' => $e->getMessage()]);
        }
        return $this->redirect(link_to('/my-account/stylist/#payouts'), 303);
    }

    public function cancel(Request $request): Response
    {
        $stylist = $this->approved();
        if ((new Earnings($this->app->db(), Earnings::STYLIST))->cancel((int) $stylist['id'], (int) $request->param('id'))) {
            $this->app->session()->flash('account_notice', 'Payout request cancelled.');
        }
        return $this->redirect(link_to('/my-account/stylist/#payouts'), 303);
    }

    private function approved(): array
    {
        $stylist = $this->app->db()->first("SELECT * FROM stylists WHERE user_id = ? AND status = 'approved'", [$this->user()['id']]);
        return $stylist ?? throw new HttpException(404);
    }

    private function user(): array
    {
        return $this->app->auth()->user() ?? throw new HttpException(401);
    }

    private function page(string $view, array $data): Response
    {
        $seo = Seo::make('Stylist program');
        $seo->robots = 'noindex, nofollow';
        return $this->view($view, $data + [
            'seo' => $seo,
            'tab' => 'stylist',
            'user' => $this->user(),
            'isVendor' => $this->app->auth()->hasRole('vendor'),
            'notice' => $this->app->session()->getFlash('account_notice'),
            'errors' => $this->app->session()->getFlash('_errors', []),
        ])->header('Cache-Control', 'private, no-store');
    }
}
