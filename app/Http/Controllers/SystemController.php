<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Migrator;

final class SystemController extends Controller
{
    public function health(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }

    /** Environment checklist, only reachable when APP_ENV=local. */
    public function status(Request $request): Response
    {
        if (!$this->app->isLocal()) {
            throw new HttpException(404);
        }

        $checks = [];
        $checks[] = ['PHP ≥ 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION];
        foreach (['pdo_mysql' => 'database', 'openssl' => 'security', 'sodium' => '2FA / encryption',
                  'curl' => 'Stripe API (Phase 3)', 'mbstring' => 'text handling', 'gd' => 'image resizing (Phase 1)',
                  'intl' => 'optional', 'zip' => 'optional, CSV/backup exports'] as $ext => $why) {
            $checks[] = ["ext-{$ext}", extension_loaded($ext), $why];
        }
        $checks[] = ['Argon2id password hashing', defined('PASSWORD_ARGON2ID'), defined('PASSWORD_ARGON2ID') ? 'available' : 'falls back to bcrypt'];
        $checks[] = ['APP_KEY set', strlen((string) config('app.key')) === 64, 'php bin/console key:generate'];
        foreach (['storage/cache', 'storage/logs', 'storage/sessions', 'public/uploads'] as $dir) {
            $checks[] = ["{$dir} writable", is_writable($this->app->path($dir)), ''];
        }

        $dbOk = false;
        $dbNote = '';
        $migrations = [];
        try {
            $dbOk = (bool) $this->app->db()->scalar('SELECT 1');
            $dbNote = (string) $this->app->db()->scalar('SELECT VERSION()');
            $migrations = (new Migrator($this->app->db(), $this->app->path('database/migrations')))->status();
        } catch (\Throwable $e) {
            $dbNote = $e instanceof \PDOException ? 'Cannot connect: check DB_* in .env' : 'Error: ' . get_class($e);
        }
        $checks[] = ['MySQL connection', $dbOk, $dbNote];
        if ($dbOk) {
            $pending = count(array_filter($migrations, static fn ($ran) => !$ran));
            $checks[] = ['Migrations up to date', $pending === 0, $pending ? "{$pending} pending: php bin/console migrate" : count($migrations) . ' applied'];
        }

        return $this->view('system/status', [
            'title' => 'Environment status',
            'checks' => $checks,
            'baseUrl' => $this->app->url()->base(),
            'host' => $this->app->url()->hostName(),
            'scheme' => $this->app->url()->scheme(),
        ]);
    }

    /** Fresh CSRF token for forms on cached pages (starts the visitor's session). */
    public function csrfToken(Request $request): Response
    {
        return Response::json(['token' => csrf_token()])->header('Cache-Control', 'no-store, private');
    }
}
