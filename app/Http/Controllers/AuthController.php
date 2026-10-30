<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Auth;
use App\Core\Security\PasswordHasher;
use App\Core\Security\TwoFactor;
use App\Services\Cart\Cart;
use App\Services\Mail\Mailer;
use App\Services\Points;
use App\Services\Seo;
use App\Services\Settings;

/** Login (with lockout and 2FA), registration, password reset, logout. */
final class AuthController extends Controller
{
    private const RESET_MINUTES = 60;
    private const WELCOME_DAYS = 7;

    public function showLogin(Request $request): Response
    {
        if ($redirect = $this->safeRedirect($request->string('redirect'))) {
            $this->app->session()->set('url.intended', $redirect);
        }
        $seo = Seo::make('My account');
        $seo->robots = 'noindex, follow';
        return $this->view('account/login', [
            'seo' => $seo,
            'errors' => $this->app->session()->getFlash('_errors', []),
            'loginError' => $this->app->session()->getFlash('login_error'),
            'notice' => $this->app->session()->getFlash('account_notice'),
            'registerAs' => $request->string('register') === 'vendor' ? 'vendor' : old('role', 'customer'),
            'signupPoints' => Points::enabled() ? (int) Settings::get('points.signup_points', 0) : 0,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request): Response
    {
        $identifier = mb_substr_safe($request->string('username'), 191);
        $password = (string) $request->input('password', '');
        $limiter = $this->app->limiter();
        [$max, $decay] = config('security.rate_limits.login');
        $keys = ['login:ip:' . $request->ip(), 'login:id:' . strtolower($identifier)];
        foreach ($keys as $key) {
            if ($limiter->tooManyAttempts($key, (int) $max)) {
                $minutes = (int) ceil($limiter->availableIn($key) / 60);
                return $this->loginFailed('Too many failed login attempts. Please try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.', $identifier);
            }
        }

        $user = $identifier !== '' && $password !== '' ? $this->app->auth()->validate($identifier, $password) : null;
        if ($user === null || $user['status'] !== 'active') {
            foreach ($keys as $key) {
                $limiter->hit($key, (int) $decay);
            }
            // Same message whether the account exists or not.
            return $this->loginFailed($user && $user['status'] === 'suspended' ? 'This account has been suspended. Please contact us.' : 'Incorrect username/email or password.', $identifier);
        }
        foreach ($keys as $key) {
            $limiter->clear($key);
        }

        $remember = in_array($request->input('rememberme'), ['1', 'on', 'forever'], true);
        if ($user['two_factor_enabled_at']) {
            $session = $this->app->session();
            $session->regenerate();
            $session->set('2fa_pending', ['user_id' => (int) $user['id'], 'remember' => $remember, 'at' => time()]);
            return $this->redirect('/my-account/two-factor/', 303);
        }
        return $this->completeLogin($request, $user, $remember);
    }

    public function showTwoFactor(Request $request): Response
    {
        if (!$this->pendingTwoFactor()) {
            return $this->redirect('/my-account/', 303);
        }
        $seo = Seo::make('Two-factor authentication');
        $seo->robots = 'noindex, nofollow';
        return $this->view('account/two-factor-challenge', ['seo' => $seo, 'error' => $this->app->session()->getFlash('twofa_error')]);
    }

    public function verifyTwoFactor(Request $request): Response
    {
        $pending = $this->pendingTwoFactor() ?? throw new HttpException(419);
        $key = '2fa:' . $pending['user_id'];
        if ($this->app->limiter()->tooManyAttempts($key, 5)) {
            $this->app->session()->forget('2fa_pending');
            $this->app->session()->flash('login_error', 'Too many incorrect codes. Please log in again.');
            return $this->redirect('/my-account/', 303);
        }
        $user = $this->app->db()->first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$pending['user_id']]) ?? throw new HttpException(419);
        $code = strtolower(trim($request->string('code')));
        $secret = $user['two_factor_secret'] ? TwoFactor::decrypt((string) $user['two_factor_secret']) : null;
        $ok = $secret !== null && TwoFactor::verify($secret, $code);
        if (!$ok) {
            // One-time recovery code?
            $codes = json_decode((string) $user['two_factor_recovery'], true) ?: [];
            foreach ($codes as $i => $hash) {
                if (hash_equals($hash, hash('sha256', $code))) {
                    unset($codes[$i]);
                    $this->app->db()->update('users', ['two_factor_recovery' => json_encode(array_values($codes))], ['id' => $user['id']]);
                    $ok = true;
                    break;
                }
            }
        }
        if (!$ok) {
            $this->app->limiter()->hit($key, 900);
            $this->app->session()->flash('twofa_error', 'That code is not valid. Please try again.');
            return $this->redirect('/my-account/two-factor/', 303);
        }
        $this->app->limiter()->clear($key);
        $this->app->session()->forget('2fa_pending');
        return $this->completeLogin($request, $user, (bool) $pending['remember']);
    }

    public function register(Request $request): Response
    {
        if ($request->string('website') !== '') {
            return $this->redirect('/my-account/', 303); // honeypot
        }
        $limiter = $this->app->limiter();
        [$max, $decay] = config('security.rate_limits.register');
        if ($limiter->hit('register:' . $request->ip(), (int) $decay) > (int) $max) {
            throw new HttpException(429);
        }

        $isVendor = $request->string('role') === 'vendor';
        $rules = ['email' => 'required|email', 'role' => 'required|in:customer,vendor'];
        if ($isVendor) {
            $rules += [
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'shopname' => 'required|string|min:2|max:120',
                'shopurl' => 'required|slug|min:3|max:80',
                'phone' => 'required|regex:/^[0-9 +().-]{7,30}$/',
            ];
        }
        $data = $this->validate($request, $rules);
        $email = strtolower($data['email']);
        $db = $this->app->db();

        $errors = [];
        if ($db->scalar('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors['email'] = 'An account is already registered with your email address. Please log in.';
        }
        if ($isVendor && ($db->scalar('SELECT 1 FROM vendors WHERE slug = ?', [$data['shopurl']]) || in_array($data['shopurl'], ['admin', 'dashboard', 'store', 'shop', 'nica'], true))) {
            $errors['shopurl'] = 'This shop URL is already taken. Please choose another one.';
        }
        if ($errors) {
            throw new \App\Core\Http\ValidationException($errors, $request);
        }

        $userId = $db->transaction(function () use ($db, $email, $data, $isVendor) {
            $userId = $db->insert('users', [
                'email' => $email,
                'username' => $this->uniqueUsername($email),
                'password_hash' => null,
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'display_name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: strstr($email, '@', true),
                'phone' => $data['phone'] ?? null,
            ]);
            $db->insert('user_roles', ['user_id' => $userId, 'role' => 'customer']);
            if ($isVendor) {
                $db->insert('user_roles', ['user_id' => $userId, 'role' => 'vendor']);
                $db->insert('vendors', [
                    'user_id' => $userId,
                    'slug' => $data['shopurl'],
                    'store_name' => $data['shopname'],
                    'status' => 'pending',
                    'phone' => $data['phone'],
                ]);
            }
            if (Points::enabled() && ($signup = (int) Settings::get('points.signup_points', 0)) > 0) {
                (new Points($db))->add($userId, $signup, 'signup', null, 'Thanks for signing up');
            }
            return $userId;
        });

        $user = $db->first('SELECT * FROM users WHERE id = ?', [$userId]);
        $this->sendPasswordLink($user, 'welcome');
        if ($isVendor) {
            (new Mailer())->queue((string) \App\Services\Settings::get('site.contact.email'), '[' . config('app.name') . '] New vendor application: ' . $data['shopname'],
                $this->app->view()->render('emails/vendor-application', ['user' => $user, 'shop' => $data['shopname'], 'slug' => $data['shopurl']]));
        }

        // Like WooCommerce: signed in straight away; the email link sets the password.
        $this->app->session()->flash('account_notice', $isVendor
            ? 'Thanks for applying to sell on NiCa! We have emailed you a link to set your password. Our team will review your store and let you know when it is approved.'
            : 'Your account was created. We have emailed you a link to set your password.');
        return $this->completeLogin($request, $user, false);
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout();
        return $this->redirect('/my-account/', 303)->cookie(Auth::REMEMBER_COOKIE, '', ['expires' => time() - 3600]);
    }

    public function showLostPassword(Request $request): Response
    {
        $seo = Seo::make('Lost password');
        $seo->robots = 'noindex, follow';
        return $this->view('account/lost-password', ['seo' => $seo, 'sent' => $this->app->session()->getFlash('reset_sent', false), 'errors' => $this->app->session()->getFlash('_errors', [])]);
    }

    public function sendResetLink(Request $request): Response
    {
        $data = $this->validate($request, ['user_login' => 'required|string|max:191']);
        [$max, $decay] = config('security.rate_limits.password_reset');
        if ($this->app->limiter()->hit('reset:' . $request->ip(), (int) $decay) > (int) $max) {
            throw new HttpException(429);
        }
        $id = trim($data['user_login']);
        $user = $this->app->db()->first(str_contains($id, '@') ? 'SELECT * FROM users WHERE email = ?' : 'SELECT * FROM users WHERE username = ?', [str_contains($id, '@') ? strtolower($id) : $id]);
        if ($user && $user['status'] === 'active') {
            $this->sendPasswordLink($user, 'reset');
        }
        // Never reveal whether an account exists.
        $this->app->session()->flash('reset_sent', true);
        return $this->redirect('/my-account/lost-password/', 303);
    }

    public function showResetPassword(Request $request): Response
    {
        $row = $this->tokenRow($request->string('token'));
        $seo = Seo::make($row && $row['purpose'] === 'welcome' ? 'Set your password' : 'Reset password');
        $seo->robots = 'noindex, nofollow';
        return $this->view('account/reset-password', [
            'seo' => $seo, 'valid' => $row !== null, 'token' => $request->string('token'),
            'welcome' => $row && $row['purpose'] === 'welcome', 'errors' => $this->app->session()->getFlash('_errors', []),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function resetPassword(Request $request): Response
    {
        $row = $this->tokenRow($request->string('token'));
        if ($row === null) {
            $this->app->session()->flash('login_error', 'This password link is invalid or has expired. Please request a new one.');
            return $this->redirect('/my-account/lost-password/', 303);
        }
        $min = (int) config('security.password.min_length', 10);
        $data = $this->validate($request, ['password' => 'required|string|min:' . $min . '|max:200|confirmed']);
        $db = $this->app->db();
        $db->transaction(function () use ($db, $row, $data) {
            // An account whose email was never proven could have been set up by someone else
            // (registered with this address before its owner): the owner's emailed link wins,
            // so any 2FA that stranger turned on is removed too.
            $unverified = $db->scalar('SELECT email_verified_at IS NULL FROM users WHERE id = ?', [$row['user_id']]);
            $db->update('users', ['password_hash' => PasswordHasher::hash($data['password']), 'email_verified_at' => gmdate('Y-m-d H:i:s')]
                + ($unverified ? ['two_factor_secret' => null, 'two_factor_enabled_at' => null, 'two_factor_recovery' => null] : []), ['id' => (int) $row['user_id']]);
            $db->update('password_resets', ['used_at' => gmdate('Y-m-d H:i:s')], ['user_id' => (int) $row['user_id']]);
        });
        // New password: other devices are signed out (session fingerprint changes; remember tokens revoked).
        $this->app->auth()->forgetRememberTokens((int) $row['user_id']);
        $user = $db->first('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        $this->app->session()->flash('account_notice', 'Your password has been saved.');
        if ($user['two_factor_enabled_at']) {
            $this->app->session()->flash('login_error', 'Your password has been saved. Please log in.');
            return $this->redirect('/my-account/', 303);
        }
        return $this->completeLogin($request, $user, false);
    }

    /* -------------------------------------------------------------- helpers */

    private function completeLogin(Request $request, array $user, bool $remember): Response
    {
        $auth = $this->app->auth();
        $intended = $this->app->session()->get('url.intended');
        $auth->login($user, $request->ip());
        if (is_string($intended)) {
            $this->app->session()->set('url.intended', $intended); // login() regenerated the session
        }
        // Keep what the visitor put in their cart before logging in.
        (new Cart($this->app->db(), $request, (int) $user['id']))->mergeGuestCart();

        $target = $this->safeRedirect((string) $this->app->session()->get('url.intended', '')) ?? '/my-account/';
        $this->app->session()->forget('url.intended');
        $response = $this->redirect($target, 303)->cookie(Cart::COOKIE, '', ['expires' => time() - 3600]);
        if ($remember) {
            [$name, $value, $expires] = $auth->remember((int) $user['id'], $request->userAgent());
            $response->cookie($name, $value, ['expires' => $expires]);
        }
        return $response;
    }

    private function loginFailed(string $message, string $identifier): Response
    {
        $session = $this->app->session();
        $session->flash('login_error', $message);
        $session->flash('_old', ['username' => $identifier]);
        return $this->redirect('/my-account/', 303);
    }

    private function pendingTwoFactor(): ?array
    {
        $p = $this->app->session()->get('2fa_pending');
        return is_array($p) && time() - (int) $p['at'] < 600 ? $p : null;
    }

    /** Also used by the admin panel ("send password reset"). */
    public function sendPasswordLink(array $user, string $purpose): void
    {
        $db = $this->app->db();
        $token = bin2hex(random_bytes(32));
        $db->query("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL", [$user['id']]);
        $db->insert('password_resets', [
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($purpose === 'welcome' ? 86400 * self::WELCOME_DAYS : 60 * self::RESET_MINUTES)),
        ]);
        $link = $this->app->url()->to('/my-account/reset-password/', ['token' => $token]);
        (new Mailer())->queue($user['email'],
            $purpose === 'welcome' ? 'Your ' . config('app.name') . ' account has been created!' : 'Password reset request for ' . config('app.name'),
            $this->app->view()->render('emails/password-link', ['user' => $user, 'link' => $link, 'welcome' => $purpose === 'welcome', 'minutes' => self::RESET_MINUTES]));
    }

    private function tokenRow(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return $this->app->db()->first(
            "SELECT pr.* FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP() AND u.status = 'active'",
            [hash('sha256', $token)],
        );
    }

    /** Only same-site paths (no "//evil.com", no schemes). */
    private function safeRedirect(string $path): ?string
    {
        return $path !== '' && $path[0] === '/' && !str_starts_with($path, '//') && !str_contains($path, '\\') && strlen($path) < 500 ? $path : null;
    }

    private function uniqueUsername(string $email): string
    {
        $base = substr(preg_replace('/[^a-z0-9._-]/', '', strtolower((string) strstr($email, '@', true))) ?: 'customer', 0, 50);
        $name = $base;
        for ($i = 2; $this->app->db()->scalar('SELECT 1 FROM users WHERE username = ?', [$name]); $i++) {
            $name = $base . $i;
        }
        return $name;
    }
}
