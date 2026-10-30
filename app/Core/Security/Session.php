<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Hardened native session, started lazily: anonymous visitors who only browse
 * never get a cookie, which keeps full-page caching possible.
 */
final class Session
{
    private bool $started = false;
    /** In-memory store when running under CLI/tests. */
    private array $memory = [];

    public function __construct(private string $savePath, private array $config)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        if (PHP_SAPI === 'cli') {
            $this->ageFlash();
            return;
        }

        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0770, true);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        if (PHP_VERSION_ID < 80400) {
            // PHP 8.4+ already uses long random ids by default (and deprecates these settings).
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string) (((int) $this->config['idle_minutes']) * 60));
        session_save_path($this->savePath);
        session_name((string) $this->config['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (app()->request()->basePath() ?: '') . '/',
            'secure' => app()->url()->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $created = (int) ($_SESSION['_created'] ?? $now);
        $last = (int) ($_SESSION['_last'] ?? $now);
        $idle = ((int) $this->config['idle_minutes']) * 60;
        $absolute = ((int) $this->config['absolute_hours']) * 3600;
        if ($now - $last > $idle || $now - $created > $absolute) {
            $_SESSION = [];
            session_regenerate_id(true);
            $created = $now;
        }
        $_SESSION['_created'] = $created;
        $_SESSION['_last'] = $now;
        $this->ageFlash();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /** True when the browser already has a session cookie (no need to create one). */
    public function hasCookie(): bool
    {
        return isset($_COOKIE[(string) $this->config['name']]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Reading from a session the browser does not have yet must not create one.
        if (!$this->started && !$this->hasCookie() && PHP_SAPI !== 'cli') {
            return $default;
        }
        $this->start();
        return $this->store()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $store = &$this->storeRef();
        $store[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        $store = &$this->storeRef();
        unset($store[$key]);
    }

    /** Value available on the next request only. */
    public function flash(string $key, mixed $value): void
    {
        $this->start();
        $store = &$this->storeRef();
        $store['_flash_next'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        if (!$this->started && !$this->hasCookie() && PHP_SAPI !== 'cli') {
            return $default;
        }
        $this->start();
        return $this->store()['_flash_now'][$key] ?? $default;
    }

    /** New session ID; call on login, logout and privilege change (prevents fixation). */
    public function regenerate(): void
    {
        $this->start();
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
    }

    public function invalidate(): void
    {
        $this->start();
        if (PHP_SAPI === 'cli') {
            $this->memory = [];
            return;
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_created'] = $_SESSION['_last'] = time();
    }

    public function save(): void
    {
        if ($this->started && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function ageFlash(): void
    {
        $store = &$this->storeRef();
        $store['_flash_now'] = $store['_flash_next'] ?? [];
        $store['_flash_next'] = [];
    }

    private function store(): array
    {
        return PHP_SAPI === 'cli' ? $this->memory : ($_SESSION ?? []);
    }

    private function &storeRef(): array
    {
        if (PHP_SAPI === 'cli') {
            return $this->memory;
        }
        return $_SESSION;
    }
}
