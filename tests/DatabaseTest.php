<?php

declare(strict_types=1);

use App\Core\Security\PasswordHasher;

// Integration tests against the local database. Skipped when MySQL is unreachable.

$dbAvailable = static function (): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) app()->db()->scalar('SELECT 1 FROM users LIMIT 1') || true;
        } catch (\Throwable) {
            $ok = false;
        }
    }
    return $ok;
};

/** Creates a throwaway user, runs $fn, always deletes the user. */
$withUser = static function (string $hash, callable $fn): void {
    $db = app()->db();
    $email = 'test+' . bin2hex(random_bytes(4)) . '@nica.test';
    $id = $db->insert('users', ['email' => $email, 'password_hash' => $hash, 'display_name' => 'Test']);
    try {
        $fn($id, $email);
    } finally {
        $db->delete('users', ['id' => $id]);
    }
};

test('db: imported WordPress user can log in and the hash is upgraded to Argon2id', function () use ($dbAvailable, $withUser) {
    if (!$dbAvailable()) {
        echo "      (skipped: no database)\n";
        return;
    }
    $withUser('$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0', function (int $id, string $email) {
        $auth = app()->auth();
        assert_same(null, $auth->validate($email, 'wrong-password'));
        $user = $auth->validate(strtoupper($email), 'test12345');
        assert_same($id, (int) $user['id']);
        $stored = (string) app()->db()->scalar('SELECT password_hash FROM users WHERE id = ?', [$id]);
        assert_true(str_starts_with($stored, '$argon2id$'), 'hash should be upgraded');
        assert_true(PasswordHasher::verify('test12345', $stored));
    });
});

test('db: login stores session, roles resolve, password change kills the session', function () use ($dbAvailable, $withUser) {
    if (!$dbAvailable()) {
        return;
    }
    $withUser(PasswordHasher::hash('a-long-password'), function (int $id, string $email) {
        $db = app()->db();
        $db->insert('user_roles', ['user_id' => $id, 'role' => 'vendor']);

        $session = new App\Core\Security\Session(sys_get_temp_dir(), ['name' => 't', 'idle_minutes' => 5, 'absolute_hours' => 1]);
        $auth = new App\Core\Security\Auth($db, $session);
        $auth->login($auth->validate($email, 'a-long-password'));
        assert_true($auth->hasRole('vendor'));
        assert_false($auth->hasRole('admin'));

        // A fresh Auth on the same session sees the user...
        $again = new App\Core\Security\Auth($db, $session);
        assert_same($id, $again->id());

        // ...until the password changes elsewhere.
        $db->update('users', ['password_hash' => PasswordHasher::hash('another-password!')], ['id' => $id]);
        $after = new App\Core\Security\Auth($db, $session);
        assert_same(null, $after->id());
    });
});

test('db: suspended users are logged out', function () use ($dbAvailable, $withUser) {
    if (!$dbAvailable()) {
        return;
    }
    $withUser(PasswordHasher::hash('a-long-password'), function (int $id, string $email) {
        $db = app()->db();
        $session = new App\Core\Security\Session(sys_get_temp_dir(), ['name' => 't', 'idle_minutes' => 5, 'absolute_hours' => 1]);
        $auth = new App\Core\Security\Auth($db, $session);
        $auth->login($auth->validate($email, 'a-long-password'));
        $db->update('users', ['status' => 'suspended'], ['id' => $id]);
        assert_same(null, (new App\Core\Security\Auth($db, $session))->id());
    });
});

test('db: rate limiter counts hits per window and clears', function () use ($dbAvailable) {
    if (!$dbAvailable()) {
        return;
    }
    $limiter = app()->limiter();
    $key = 'test:' . bin2hex(random_bytes(4));
    try {
        foreach ([1, 2, 3] as $expected) {
            assert_same($expected, $limiter->hit($key, 60));
        }
        assert_true($limiter->tooManyAttempts($key, 3));
        assert_false($limiter->tooManyAttempts($key, 4));
        assert_true($limiter->availableIn($key) > 0);
        $limiter->clear($key);
        assert_false($limiter->tooManyAttempts($key, 1));
    } finally {
        $limiter->clear($key);
    }
});

test('db: rate limiter window resets after expiry', function () use ($dbAvailable) {
    if (!$dbAvailable()) {
        return;
    }
    $limiter = app()->limiter();
    $key = 'test:' . bin2hex(random_bytes(4));
    try {
        $limiter->hit($key, 60);
        $limiter->hit($key, 60);
        app()->db()->update('rate_limits', ['reset_at' => time() - 1], ['k' => $key]);
        assert_same(1, $limiter->hit($key, 60), 'expired window should restart at 1');
    } finally {
        $limiter->clear($key);
    }
});

test('db: update/delete refuse to run without a WHERE clause', function () use ($dbAvailable) {
    if (!$dbAvailable()) {
        return;
    }
    assert_throws(\InvalidArgumentException::class, fn () => app()->db()->update('users', ['status' => 'active'], []));
    assert_throws(\InvalidArgumentException::class, fn () => app()->db()->delete('users', []));
    assert_throws(\InvalidArgumentException::class, fn () => app()->db()->insert('users; DROP TABLE users', ['email' => 'x']));
});

test('db: nested transactions roll back to the savepoint', function () use ($dbAvailable) {
    if (!$dbAvailable()) {
        return;
    }
    $db = app()->db();
    $name = 'test.tx.' . bin2hex(random_bytes(4));
    try {
        $db->transaction(function () use ($db, $name) {
            $db->insert('settings', ['name' => $name . '.outer', 'value' => '1']);
            try {
                $db->transaction(function () use ($db, $name) {
                    $db->insert('settings', ['name' => $name . '.inner', 'value' => '1']);
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
            }
        });
        assert_same(1, (int) $db->scalar('SELECT COUNT(*) FROM settings WHERE name LIKE ?', [$name . '%']));
    } finally {
        $db->query('DELETE FROM settings WHERE name LIKE ?', [$name . '%']);
    }
});
