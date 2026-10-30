<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Database;

/**
 * Session authentication. The session stores the user id plus a fingerprint of
 * the password hash, so changing a password logs out every other session.
 */
final class Auth
{
    private ?array $user = null;
    private bool $resolved = false;

    public function __construct(private Database $db, private Session $session)
    {
    }

    /**
     * Verify credentials (email address or username). Returns the user row or null.
     * Does not log in, so callers can check status / 2FA first.
     */
    public function validate(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        $user = str_contains($identifier, '@')
            ? $this->db->first('SELECT * FROM users WHERE email = ?', [strtolower($identifier)])
            : $this->db->first('SELECT * FROM users WHERE username = ?', [$identifier]);
        if (!PasswordHasher::verify($password, $user['password_hash'] ?? null) || $user === null) {
            return null;
        }
        if (PasswordHasher::needsRehash((string) $user['password_hash'])) {
            $user['password_hash'] = PasswordHasher::hash($password);
            $this->db->update('users', ['password_hash' => $user['password_hash']], ['id' => $user['id']]);
        }
        return $user;
    }

    public function login(array $user, string $ip = '', bool $track = true): void
    {
        $this->session->regenerate();
        $this->session->set('auth_id', (int) $user['id']);
        $this->session->set('auth_fp', $this->fingerprint($user));
        $this->session->forget('_csrf');
        if ($track) {
            $this->db->update('users', ['last_login_at' => gmdate('Y-m-d H:i:s'), 'last_login_ip' => $ip ?: null], ['id' => $user['id']]);
        }
        unset($user['_roles']);
        $this->user = $user;
        $this->resolved = true;
    }

    public const REMEMBER_COOKIE = 'nica_remember';
    private const REMEMBER_DAYS = 30;

    /** Long-lived login: selector + validator cookie, only the validator's hash is stored. */
    public function remember(int $userId, string $userAgent): array
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $this->db->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * self::REMEMBER_DAYS),
            'user_agent' => mb_substr_safe($userAgent, 255),
        ]);
        return [self::REMEMBER_COOKIE, $selector . ':' . $validator, time() + 86400 * self::REMEMBER_DAYS];
    }

    /** Log in from a remember-me cookie. The token is single use and rotated. Returns a new cookie value or null. */
    public function loginFromRememberCookie(string $cookie, string $ip, string $userAgent): ?array
    {
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $m)) {
            return null;
        }
        $row = $this->db->first('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > UTC_TIMESTAMP()', [$m[1]]);
        if ($row === null) {
            return null;
        }
        $this->db->delete('remember_tokens', ['id' => (int) $row['id']]);
        if (!hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
            // A stolen/replayed token: revoke them all for this user.
            $this->db->delete('remember_tokens', ['user_id' => (int) $row['user_id']]);
            return null;
        }
        $user = $this->db->first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$row['user_id']]);
        if ($user === null) {
            return null;
        }
        // The device passed 2FA when the token was issued, so 2FA is not asked again here.
        $this->login($user, $ip);
        return $this->remember((int) $user['id'], $userAgent);
    }

    public function forgetRememberTokens(int $userId): void
    {
        $this->db->delete('remember_tokens', ['user_id' => $userId]);
    }

    /**
     * Staff support: act as another account (never an admin/staff one). The
     * original admin is kept in the session so they can switch back.
     */
    public function impersonate(array $target): void
    {
        $adminId = $this->id();
        if ($adminId === null || (int) $target['id'] === $adminId) {
            throw new \DomainException('Cannot switch to this account.');
        }
        if ($this->db->scalar("SELECT 1 FROM user_roles WHERE user_id = ? AND role IN ('admin','staff')", [$target['id']])) {
            throw new \DomainException('Staff accounts cannot be impersonated.');
        }
        $this->login($target, '', false);
        $this->session->set('impersonator_id', $adminId);
    }

    /** @return int|null the admin to return to */
    public function stopImpersonating(): ?int
    {
        $adminId = (int) $this->session->get('impersonator_id', 0);
        if ($adminId === 0) {
            return null;
        }
        $admin = $this->db->first("SELECT * FROM users WHERE id = ? AND status = 'active'", [$adminId]);
        if ($admin === null) {
            $this->logout();
            return null;
        }
        $this->login($admin, '', false);
        $this->session->forget('impersonator_id');
        return $adminId;
    }

    public function impersonatorId(): ?int
    {
        return $this->check() && ($id = (int) $this->session->get('impersonator_id', 0)) > 0 ? $id : null;
    }

    public function logout(): void
    {
        // While impersonating, the vendor's own devices stay signed in.
        if (($id = $this->id()) && !$this->session->get('impersonator_id')) {
            $this->forgetRememberTokens($id);
        }
        $this->session->invalidate();
        $this->user = null;
        $this->resolved = true;
    }

    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;
        if (!$this->session->isStarted() && !$this->session->hasCookie() && PHP_SAPI !== 'cli') {
            return null; // no session cookie means nobody is logged in; don't create one
        }
        $id = (int) $this->session->get('auth_id', 0);
        if ($id === 0) {
            return null;
        }
        $user = $this->db->first('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null || $user['status'] !== 'active'
            || !hash_equals((string) $this->session->get('auth_fp', ''), $this->fingerprint($user))) {
            $this->session->invalidate();
            return null;
        }
        return $this->user = $user;
    }

    public function id(): ?int
    {
        $user = $this->user();
        return $user ? (int) $user['id'] : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return string[] */
    public function roles(): array
    {
        $id = $this->id();
        if ($id === null) {
            return [];
        }
        return $this->user['_roles'] ??= array_column($this->db->select('SELECT role FROM user_roles WHERE user_id = ?', [$id]), 'role');
    }

    public function hasRole(string ...$roles): bool
    {
        return array_intersect($roles, $this->roles()) !== [];
    }

    private function fingerprint(array $user): string
    {
        $key = (string) config('app.key', '');
        return hash_hmac('sha256', $user['id'] . '|' . ($user['password_hash'] ?? ''), $key !== '' ? $key : 'nica');
    }
}
