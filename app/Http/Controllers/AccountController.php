<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Core\Security\PasswordHasher;
use App\Core\Security\TwoFactor;
use App\Services\Orders\OrderService;
use App\Services\Points;
use App\Services\Seo;

/** /my-account/ area for logged-in customers (vendors also use it for their own purchases). */
final class AccountController extends Controller
{
    private const ORDERS_PER_PAGE = 10;

    public function dashboard(Request $request): Response
    {
        if (!$this->app->auth()->check()) {
            return (new AuthController($this->app))->showLogin($request);
        }
        $user = $this->user();
        $db = $this->app->db();
        return $this->page('account/dashboard', 'dashboard', [
            'recent' => $db->select('SELECT * FROM orders WHERE user_id = ? AND status <> ? ORDER BY created_at DESC LIMIT 3', [$user['id'], 'pending']),
            'vendor' => $db->first('SELECT store_name, status, slug FROM vendors WHERE user_id = ?', [$user['id']]),
        ]);
    }

    public function orders(Request $request): Response
    {
        $user = $this->user();
        $db = $this->app->db();
        $page = max(1, (int) $request->param('page', '1'));
        // Unpaid checkout attempts are not shown.
        $where = "user_id = ? AND status NOT IN ('pending')";
        $total = (int) $db->scalar("SELECT COUNT(*) FROM orders WHERE {$where}", [$user['id']]);
        $pages = max(1, (int) ceil($total / self::ORDERS_PER_PAGE));
        $orders = $db->select(
            "SELECT o.*, (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS item_count FROM orders o WHERE {$where} ORDER BY created_at DESC LIMIT " . self::ORDERS_PER_PAGE . ' OFFSET ' . (($page - 1) * self::ORDERS_PER_PAGE),
            [$user['id']],
        );
        return $this->page('account/orders', 'orders', ['orders' => $orders, 'page' => $page, 'pages' => $pages]);
    }

    public function viewOrder(Request $request): Response
    {
        $user = $this->user();
        $order = $this->app->db()->first('SELECT * FROM orders WHERE number = ? AND user_id = ?', [(string) $request->param('number'), $user['id']])
            ?? throw new HttpException(404); // someone else's order looks exactly like a missing one
        $orders = new OrderService($this->app->db());
        return $this->page('account/view-order', 'orders', [
            'order' => $order,
            'items' => $orders->items((int) $order['id']),
            'vendorOrders' => $orders->vendorOrders((int) $order['id']),
            'notes' => $this->app->db()->select('SELECT note, created_at FROM order_notes WHERE order_id = ? AND is_customer_visible = 1 ORDER BY id', [$order['id']]),
            'refunds' => $this->app->db()->select("SELECT amount_cents, reason, created_at FROM refunds WHERE order_id = ? AND status = 'succeeded'", [$order['id']]),
        ]);
    }

    public function addresses(Request $request): Response
    {
        $user = $this->user();
        return $this->page('account/addresses', 'addresses', [
            'billing' => json_decode((string) $user['billing_address'], true) ?: [],
            'shipping' => json_decode((string) $user['shipping_address'], true) ?: [],
        ]);
    }

    public function editAddress(Request $request): Response
    {
        $type = $this->addressType($request);
        $user = $this->user();
        return $this->page('account/edit-address', 'addresses', [
            'type' => $type,
            'address' => json_decode((string) $user[$type . '_address'], true) ?: [],
            'states' => config('us_states'),
        ]);
    }

    public function saveAddress(Request $request): Response
    {
        $type = $this->addressType($request);
        $rules = [
            'first_name' => 'required|string|max:100', 'last_name' => 'required|string|max:100',
            'address_1' => 'required|string|max:200', 'address_2' => 'nullable|string|max:200',
            'city' => 'required|string|max:100', 'state' => 'required|in:' . implode(',', array_keys(config('us_states'))),
            'postcode' => 'required|regex:/^\d{5}(-\d{4})?$/',
        ];
        if ($type === 'billing') {
            $rules['phone'] = 'nullable|regex:/^[0-9 +().-]{7,30}$/';
        }
        $data = $this->validate($request, $rules);
        $data['country'] = 'US';
        $this->app->db()->update('users', [$type . '_address' => json_encode(array_filter($data, static fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE)], ['id' => $this->user()['id']]);
        $this->app->session()->flash('account_notice', 'Address changed successfully.');
        return $this->redirect('/my-account/edit-address/', 303);
    }

    public function editAccount(Request $request): Response
    {
        return $this->page('account/edit-account', 'edit-account', []);
    }

    public function saveAccount(Request $request): Response
    {
        $user = $this->user();
        $min = (int) config('security.password.min_length', 10);
        $data = $this->validate($request, [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'display_name' => 'required|string|max:100',
            'email' => 'required|email',
            'password_current' => 'nullable|string|max:200',
            'password' => 'nullable|string|min:' . $min . '|max:200|confirmed',
        ]);
        $email = strtolower($data['email']);
        $changingEmail = $email !== $user['email'];
        $changingPassword = !empty($data['password']);
        $errors = [];
        // Sensitive changes need the current password (accounts without one, e.g. new signups, set it by email link).
        if (($changingEmail || $changingPassword) && $user['password_hash'] && !PasswordHasher::verify((string) $data['password_current'], $user['password_hash'])) {
            $errors['password_current'] = 'Your current password is incorrect.';
        }
        if ($changingEmail && $this->app->db()->scalar('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) {
            $errors['email'] = 'This email address is already registered.';
        }
        if ($errors) {
            throw new ValidationException($errors, $request);
        }
        $update = ['first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'display_name' => $data['display_name'], 'email' => $email];
        if ($changingPassword) {
            $update['password_hash'] = PasswordHasher::hash($data['password']);
        }
        $this->app->db()->update('users', $update, ['id' => $user['id']]);
        $this->audit('account.updated', ['email_changed' => $changingEmail, 'password_changed' => $changingPassword]);
        if ($changingPassword) {
            // Other devices are signed out; keep this one signed in with the new fingerprint.
            $this->app->auth()->forgetRememberTokens((int) $user['id']);
            $this->app->auth()->login($this->app->db()->first('SELECT * FROM users WHERE id = ?', [$user['id']]), $request->ip());
        }
        $this->app->session()->flash('account_notice', 'Account details changed successfully.');
        return $this->redirect('/my-account/edit-account/', 303);
    }

    public function points(Request $request): Response
    {
        $user = $this->user();
        $points = new Points($this->app->db());
        return $this->page('account/points', 'points', [
            'balance' => $points->balance((int) $user['id']),
            'history' => $points->history((int) $user['id']),
            'rate' => Points::rate(),
        ]);
    }

    public function security(Request $request): Response
    {
        $user = $this->user();
        $session = $this->app->session();
        $setup = null;
        if (!$user['two_factor_enabled_at']) {
            // A fresh secret for setup lives in the session until confirmed with a code.
            $secret = $session->get('2fa_setup_secret');
            if (!is_string($secret)) {
                $secret = TwoFactor::generateSecret();
                $session->set('2fa_setup_secret', $secret);
            }
            $setup = ['secret' => trim(chunk_split($secret, 4, ' ')), 'url' => TwoFactor::otpauthUrl($secret, $user['email'], (string) config('app.name'))];
        }
        return $this->page('account/security', 'security', [
            'enabled' => (bool) $user['two_factor_enabled_at'],
            'setup' => $setup,
            'recoveryCodes' => $session->getFlash('2fa_recovery_codes'),
            'remaining' => count(json_decode((string) $user['two_factor_recovery'], true) ?: []),
        ]);
    }

    public function enableTwoFactor(Request $request): Response
    {
        $user = $this->user();
        $secret = $this->app->session()->get('2fa_setup_secret');
        if (!is_string($secret) || !TwoFactor::verify($secret, $request->string('code'))) {
            throw new ValidationException(['code' => 'That code is not valid. Check the time on your phone and try again.'], $request);
        }
        $codes = TwoFactor::recoveryCodes();
        $this->app->db()->update('users', [
            'two_factor_secret' => TwoFactor::encrypt($secret),
            'two_factor_enabled_at' => gmdate('Y-m-d H:i:s'),
            'two_factor_recovery' => json_encode(array_map(static fn ($c) => hash('sha256', $c), $codes)),
        ], ['id' => $user['id']]);
        $this->app->session()->forget('2fa_setup_secret');
        $this->app->session()->flash('2fa_recovery_codes', $codes);
        $this->audit('2fa.enabled');
        return $this->redirect('/my-account/security/', 303);
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->user();
        if ($this->app->auth()->hasRole('admin')) {
            throw new ValidationException(['password' => 'Two-factor authentication is required for administrator accounts.'], $request);
        }
        if (!$user['password_hash'] || !PasswordHasher::verify((string) $request->input('password'), $user['password_hash'])) {
            throw new ValidationException(['password' => 'Your password is incorrect.'], $request);
        }
        $this->app->db()->update('users', ['two_factor_secret' => null, 'two_factor_enabled_at' => null, 'two_factor_recovery' => null], ['id' => $user['id']]);
        $this->audit('2fa.disabled');
        $this->app->session()->flash('account_notice', 'Two-factor authentication has been turned off.');
        return $this->redirect('/my-account/security/', 303);
    }

    public function confirmLogout(Request $request): Response
    {
        return $this->page('account/logout', 'logout', []);
    }

    /* -------------------------------------------------------------- helpers */

    private function page(string $view, string $tab, array $data): Response
    {
        $seo = Seo::make('My account');
        $seo->robots = 'noindex, nofollow';
        return $this->view($view, $data + [
            'seo' => $seo,
            'tab' => $tab,
            'user' => $this->user(),
            'isVendor' => $this->app->auth()->hasRole('vendor'),
            'notice' => $this->app->session()->getFlash('account_notice'),
            'errors' => $this->app->session()->getFlash('_errors', []),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function user(): array
    {
        return $this->app->auth()->user() ?? throw new HttpException(401);
    }

    private function addressType(Request $request): string
    {
        $type = (string) $request->param('type');
        return in_array($type, ['billing', 'shipping'], true) ? $type : throw new HttpException(404);
    }

    private function audit(string $action, array $meta = []): void
    {
        $request = $this->app->request();
        $this->app->db()->insert('audit_log', [
            'user_id' => $this->app->auth()->id(), 'action' => $action, 'subject_type' => 'user', 'subject_id' => $this->app->auth()->id(),
            'meta' => $meta ? json_encode($meta) : null, 'ip' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
