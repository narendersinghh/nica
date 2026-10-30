<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Env;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Migrator;
use App\Core\Security\PasswordHasher;
use App\Services\Cache\PageCache;
use App\Services\Import\ImportRunner;
use App\Services\Install\Installer;

/**
 * /install/: the first-time setup wizard. Only reachable until setup is
 * finished (then 404), and only after typing the one-time code saved in
 * storage/setup-code.txt, so a stranger cannot take over a fresh upload.
 *
 * Steps: unlock → check → database → site → tables → import (optional) → admin → finish.
 * Each step saves its result (.env or database), so the wizard can be left and resumed.
 */
final class InstallController extends Controller
{
    public const STEPS = [
        'check' => 'Server check',
        'database' => 'Database',
        'site' => 'Site settings',
        'tables' => 'Create tables',
        'import' => 'Import from WordPress',
        'admin' => 'Admin account',
        'finish' => 'Finish',
    ];

    public function show(Request $request): Response
    {
        $installer = $this->installer();
        if (!$this->unlocked()) {
            $installer->setupCode(); // make sure the file exists to be read
            return $this->page('unlock', ['codeFile' => Installer::CODE]);
        }
        $state = $this->state();
        $step = (string) $request->query('step', '');
        if (!isset(self::STEPS[$step]) || !$this->reachable($step, $state)) {
            $step = $state['current'];
        }
        $data = ['state' => $state, 'step' => $step, 'requirements' => $installer->requirements()];
        if ($step === 'site') {
            $data['host'] = strtolower((string) preg_replace('/:\d+$/', '', (string) $request->server('HTTP_HOST', 'localhost')));
        }
        if ($step === 'import') {
            $data['steps'] = array_merge(ImportRunner::CONTENT_STEPS, ImportRunner::CATALOG_STEPS);
            $data['labels'] = ImportRunner::LABELS;
            $data['wpConfigured'] = (string) Env::get('WP_DB_DATABASE', '') !== '' && $this->session('wp_ok');
        }
        return $this->page($step, $data);
    }

    public function unlock(Request $request): Response
    {
        if ($this->installer()->checkCode((string) $request->post('code', ''))) {
            $this->app->session()->regenerate();
            $this->app->session()->set('setup_ok', true);
            return $this->go();
        }
        usleep(800000); // slow down guessing (the code has 20 random characters anyway)
        return $this->go(null, 'That code does not match. Open ' . Installer::CODE . ' again and copy the whole code.');
    }

    public function check(Request $request): Response
    {
        $this->guard();
        if (Installer::hasFailures($this->installer()->requirements())) {
            return $this->go('check', 'Please fix the items marked in red first, then check again.');
        }
        $this->done('check');
        return $this->go();
    }

    public function database(Request $request): Response
    {
        $this->guard();
        $cfg = [
            'host' => trim((string) $request->post('host', 'localhost')) ?: 'localhost',
            'port' => (int) $request->post('port', 3306) ?: 3306,
            'database' => trim((string) $request->post('database', '')),
            'username' => trim((string) $request->post('username', '')),
            'password' => (string) $request->post('password', ''),
        ];
        // An empty password field keeps the one already saved.
        if ($cfg['password'] === '' && (string) Env::get('DB_PASSWORD', '') !== '' && $cfg['username'] === (string) Env::get('DB_USERNAME', '')) {
            $cfg['password'] = (string) Env::get('DB_PASSWORD', '');
        }
        if ($cfg['database'] === '' || $cfg['username'] === '') {
            return $this->go('database', 'Enter the database name and username from hPanel → Databases.');
        }
        try {
            $info = $this->installer()->testDatabase($cfg);
        } catch (\RuntimeException $e) {
            return $this->go('database', $e->getMessage());
        }
        $values = ['DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password']];
        if (strlen((string) Env::get('APP_KEY', '')) < 32) {
            $values['APP_KEY'] = Installer::generateKey();
        }
        if (!$this->saveEnv($values)) {
            return $this->go('database');
        }
        $this->app->session()->set('setup_db_info', $info);
        return $this->go(null, null, 'Connected to ' . ($info['mariadb'] ? 'MariaDB' : 'MySQL') . ' ' . preg_replace('/-.*/', '', $info['version']) . ($info['tables'] ? ' — this database already has ' . $info['tables'] . ' tables (kept as they are).' : '.'));
    }

    public function site(Request $request): Response
    {
        $this->guard();
        $env = $request->post('env') === 'staging' ? 'staging' : 'production';
        $hosts = array_values(array_unique(array_filter(array_map(static fn ($h) => strtolower(trim($h)), explode(',', (string) $request->post('hosts', ''))))));
        foreach ($hosts as $h) {
            if (!preg_match('/^(\*\.)?[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $h)) {
                return $this->go('site', '"' . $h . '" is not a valid domain name.');
            }
        }
        if (!$hosts) {
            return $this->go('site', 'Enter the domain the site runs on.');
        }
        $values = [
            'APP_ENV' => $env,
            'APP_DEBUG' => 'false',
            'TRUSTED_HOSTS' => implode(',', $hosts),
            'TRUSTED_PROXIES' => $request->post('behind_proxy') ? '*' : '',
        ];
        $from = trim((string) $request->post('mail_from', ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $this->go('site', 'The "send emails from" address is not valid.');
        }
        $smtpHost = trim((string) $request->post('mail_host', ''));
        $values += [
            'MAIL_DRIVER' => $smtpHost !== '' ? 'smtp' : 'log',
            'MAIL_HOST' => $smtpHost,
            'MAIL_PORT' => (string) ((int) $request->post('mail_port', 465) ?: 465),
            'MAIL_ENCRYPTION' => in_array($request->post('mail_encryption'), ['ssl', 'tls'], true) ? (string) $request->post('mail_encryption') : 'ssl',
            'MAIL_USERNAME' => trim((string) $request->post('mail_username', '')) ?: $from,
            'MAIL_FROM_ADDRESS' => $from,
        ];
        if ((string) $request->post('mail_password', '') !== '') {
            $values['MAIL_PASSWORD'] = (string) $request->post('mail_password');
        }
        $stripe = [
            'STRIPE_PUBLISHABLE_KEY' => ['/^pk_(test|live)_[A-Za-z0-9]+$/', 'publishable key (pk_…)'],
            'STRIPE_SECRET_KEY' => ['/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', 'secret key (sk_…)'],
            'STRIPE_WEBHOOK_SECRET' => ['/^whsec_[A-Za-z0-9]+$/', 'webhook signing secret (whsec_…)'],
        ];
        foreach ($stripe as $key => [$pattern, $label]) {
            $v = trim((string) $request->post(strtolower($key), ''));
            if ($v === '') {
                continue; // keep what is saved (or nothing: test payments only)
            }
            if (!preg_match($pattern, $v)) {
                return $this->go('site', 'The Stripe ' . $label . ' does not look right.');
            }
            $values[$key] = $v;
        }
        if (!$this->saveEnv($values)) {
            return $this->go('site');
        }
        $this->done('site');
        return $this->go(null, null, 'Site settings saved.');
    }

    public function tables(Request $request): Response
    {
        $this->guard();
        @set_time_limit(300);
        try {
            $ran = (new Migrator($this->app->db(), $this->app->path('database/migrations')))->migrate();
        } catch (\Throwable $e) {
            return $this->go('tables', 'Creating the tables failed: ' . $e->getMessage());
        }
        return $this->go(null, null, $ran ? count($ran) . ' table groups created.' : 'The tables were already up to date.');
    }

    public function importConfig(Request $request): Response
    {
        $this->guard();
        $cfg = [
            'host' => trim((string) $request->post('wp_host', '')) ?: (string) Env::get('DB_HOST', 'localhost'),
            'database' => trim((string) $request->post('wp_database', '')),
            'username' => trim((string) $request->post('wp_username', '')),
            'password' => (string) $request->post('wp_password', ''),
            'prefix' => trim((string) $request->post('wp_prefix', 'wp_')) ?: 'wp_',
        ];
        if ($cfg['database'] === '' || !preg_match('/^[A-Za-z0-9_]{1,20}$/', $cfg['prefix'])) {
            return $this->go('import', 'Enter the name of the database holding the copy of WordPress (and a valid table prefix, usually wp_).');
        }
        try {
            $source = ImportRunner::source(array_filter($cfg, static fn ($v) => $v !== '') + ['prefix' => $cfg['prefix']]);
            $site = $source->siteUrl();
        } catch (\Throwable $e) {
            return $this->go('import', 'Could not read WordPress from that database: check the name, login and table prefix.');
        }
        $values = ['WP_DB_HOST' => $cfg['host'], 'WP_DB_DATABASE' => $cfg['database'], 'WP_DB_USERNAME' => $cfg['username'], 'WP_DB_PASSWORD' => $cfg['password'], 'WP_DB_PREFIX' => $cfg['prefix']];
        if (!$this->saveEnv($values)) {
            return $this->go('import');
        }
        $this->app->session()->set('wp_ok', true);
        return $this->go('import', null, 'Found the WordPress site ' . $site . '. Ready to import.');
    }

    /** One import step per request (JSON), so no single request runs long. */
    public function importStep(Request $request): Response
    {
        $this->guard();
        $step = (string) $request->param('step');
        if (!isset(ImportRunner::LABELS[$step])) {
            throw new HttpException(404);
        }
        @set_time_limit(300);
        $lines = [];
        try {
            ImportRunner::run([$step], false, static function (string $line) use (&$lines): void {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            });
        } catch (\Throwable $e) {
            $this->app->logger()->error('Setup import step failed', ['step' => $step, 'error' => $e->getMessage()]);
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        if ($step === 'balances') {
            $this->done('import');
        }
        return Response::json(['ok' => true, 'summary' => end($lines) ?: 'done']);
    }

    public function importSkip(Request $request): Response
    {
        $this->guard();
        $this->done('import');
        return $this->go();
    }

    public function admin(Request $request): Response
    {
        $this->guard();
        $db = $this->app->db();
        if ($request->post('skip') && (int) $db->scalar("SELECT COUNT(*) FROM user_roles WHERE role = 'admin'") > 0) {
            $this->done('admin');
            return $this->go();
        }
        $email = strtolower(trim((string) $request->post('email', '')));
        $name = trim((string) $request->post('name', ''));
        $password = (string) $request->post('password', '');
        $min = (int) config('security.password.min_length', 10);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            return $this->go('admin', 'Enter your name and a valid email address.');
        }
        if (strlen($password) < $min || $password !== (string) $request->post('password_confirmation', '')) {
            return $this->go('admin', 'The password must be at least ' . $min . ' characters and both entries must match.');
        }
        $existing = $db->first('SELECT * FROM users WHERE email = ?', [$email]);
        if ($existing) {
            $db->update('users', ['password_hash' => PasswordHasher::hash($password), 'status' => 'active', 'email_verified_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            $id = $db->insert('users', [
                'email' => $email, 'username' => substr((string) preg_replace('/[^a-z0-9]/', '', strstr($email, '@', true) ?: 'admin'), 0, 40) . random_int(10, 99),
                'password_hash' => PasswordHasher::hash($password), 'first_name' => $first, 'last_name' => $last, 'display_name' => $name,
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        foreach (['admin', 'customer'] as $role) {
            $db->query('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)', [$id, $role]);
        }
        $this->app->session()->set('setup_admin_email', $email);
        $this->done('admin');
        return $this->go(null, null, 'Admin account ready: ' . $email . '.');
    }

    public function finish(Request $request): Response
    {
        $this->guard();
        $state = $this->state();
        if ($state['current'] !== 'finish') {
            return $this->go(null, 'Some steps are not finished yet.');
        }
        $this->installer()->markInstalled('setup wizard');
        PageCache::flush();
        $this->app->session()->forget('setup_ok');
        $base = $this->app->url()->base();
        return $this->page('done', [
            'adminEmail' => (string) $this->app->session()->get('setup_admin_email', ''),
            'base' => $base,
            'cron' => 'php ' . $this->app->path('bin/console') . ' queue:work',
            'webhook' => $base . '/webhooks/stripe',
            'stripeSet' => (string) Env::get('STRIPE_SECRET_KEY', '') !== '',
            'mailSet' => (string) Env::get('MAIL_DRIVER', 'log') === 'smtp',
            'production' => (string) Env::get('APP_ENV') === 'production',
        ]);
    }

    /* ---------------------------------------------------------------- helpers */

    /** Where the setup stands, worked out from .env and the database (so it survives a break). */
    private function state(): array
    {
        $done = (array) $this->app->session()->get('setup_done', []);
        $db = ['ok' => false, 'tables' => false, 'pending' => 0, 'admins' => 0, 'products' => 0];
        if ((string) Env::get('DB_DATABASE', '') !== '' && is_file($this->app->path('.env'))) {
            try {
                $conn = $this->app->db();
                $conn->scalar('SELECT 1');
                $db['ok'] = true;
                $status = (new Migrator($conn, $this->app->path('database/migrations')))->status();
                $db['pending'] = count(array_filter($status, static fn (bool $ran) => !$ran));
                $db['tables'] = $db['pending'] === 0;
                if ($db['tables']) {
                    $db['admins'] = (int) $conn->scalar("SELECT COUNT(*) FROM user_roles WHERE role = 'admin'");
                    $db['products'] = (int) $conn->scalar('SELECT COUNT(*) FROM products');
                }
            } catch (\Throwable) {
                $db['ok'] = false;
            }
        }
        $complete = [
            'check' => in_array('check', $done, true),
            'database' => $db['ok'],
            'site' => in_array('site', $done, true) || ((string) Env::get('TRUSTED_HOSTS', '') !== '' && $db['ok']),
            'tables' => $db['tables'],
            // Already has a catalog (e.g. the database was uploaded): nothing to import.
            'import' => in_array('import', $done, true) || $db['products'] > 0,
            'admin' => in_array('admin', $done, true) || $db['admins'] > 0,
        ];
        $current = 'finish';
        foreach ($complete as $step => $ok) {
            if (!$ok) {
                $current = $step;
                break;
            }
        }
        return ['complete' => $complete, 'current' => $current, 'db' => $db];
    }

    /** A step can be opened once every step before it is complete. */
    private function reachable(string $step, array $state): bool
    {
        foreach (array_keys(self::STEPS) as $s) {
            if ($s === $step) {
                return true;
            }
            if (empty($state['complete'][$s])) {
                return false;
            }
        }
        return false;
    }

    private function saveEnv(array $values): bool
    {
        try {
            $this->installer()->writeEnv($values);
            return true;
        } catch (\InvalidArgumentException $e) {
            $this->app->session()->flash('setup_error', $e->getMessage());
            return false;
        } catch (\RuntimeException) {
            // Not writable: show the finished file so it can be created by hand.
            $this->app->session()->flash('setup_env', $this->installer()->writeEnv($values, false));
            $this->app->session()->flash('setup_error', 'The settings file could not be saved. Create it yourself as shown below, then continue.');
            return false;
        }
    }

    private function done(string $step): void
    {
        $done = (array) $this->app->session()->get('setup_done', []);
        $done[] = $step;
        $this->app->session()->set('setup_done', array_values(array_unique($done)));
    }

    private function unlocked(): bool
    {
        return $this->app->session()->get('setup_ok') === true;
    }

    private function session(string $key): bool
    {
        return (bool) $this->app->session()->get($key, false);
    }

    private function guard(): void
    {
        if (!$this->unlocked()) {
            throw new HttpException(403);
        }
    }

    private function go(?string $step = null, ?string $error = null, ?string $notice = null): Response
    {
        if ($error !== null) {
            $this->app->session()->flash('setup_error', $error);
        }
        if ($notice !== null) {
            $this->app->session()->flash('setup_notice', $notice);
        }
        return $this->redirect(link_to('/install/') . ($step ? '?step=' . $step : ''), 303);
    }

    private function page(string $step, array $data = []): Response
    {
        return $this->view('install/wizard', $data + [
            'step' => $step,
            'steps' => self::STEPS,
            'error' => $this->app->session()->getFlash('setup_error'),
            'notice' => $this->app->session()->getFlash('setup_notice'),
            'envContent' => $this->app->session()->getFlash('setup_env'),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function installer(): Installer
    {
        return new Installer($this->app->path());
    }
}
