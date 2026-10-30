<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Security\Auth;
use App\Core\Security\PasswordHasher;
use App\Core\Security\Session;
use App\Core\Security\TwoFactor;
use App\Services\Points;

test('2fa: TOTP matches the RFC 6238 test vector and the secret round-trips encrypted', function () {
    // RFC 6238 appendix B: secret "12345678901234567890", T = 59 s -> 94287082 (last 6 digits).
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    assert_same('287082', TwoFactor::code($secret, intdiv(59, 30)));
    assert_true(TwoFactor::verify($secret, '287 082', 59));
    assert_false(TwoFactor::verify($secret, '287083', 59));
    assert_false(TwoFactor::verify($secret, '287082', 59 + 300), 'old codes expire');
    $fresh = TwoFactor::generateSecret();
    assert_same(32, strlen($fresh));
    assert_same($fresh, TwoFactor::decrypt(TwoFactor::encrypt($fresh)));
    $tampered = TwoFactor::encrypt($fresh);
    $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
    assert_same(null, TwoFactor::decrypt($tampered));
});

$withUser = static function (callable $fn): void {
    $db = app()->db();
    $email = 'acct+' . bin2hex(random_bytes(3)) . '@nica.test';
    $id = $db->insert('users', ['email' => $email, 'username' => substr(strstr($email, '@', true), 0, 40), 'password_hash' => PasswordHasher::hash('a-long-password'), 'display_name' => 'T']);
    try {
        $fn($id, $email);
    } finally {
        $db->delete('users', ['id' => $id]);
        $db->query("DELETE FROM rate_limits WHERE k LIKE 'login:%'");
    }
};

test('remember-me: token logs in once, rotates, and a replayed token revokes all', function () use ($withUser) {
    $withUser(function (int $id) {
        $db = app()->db();
        $auth = new Auth($db, new Session(sys_get_temp_dir(), ['name' => 't', 'idle_minutes' => 5, 'absolute_hours' => 1]));
        [, $cookie] = $auth->remember($id, 'test');
        $auth2 = new Auth($db, new Session(sys_get_temp_dir(), ['name' => 't2', 'idle_minutes' => 5, 'absolute_hours' => 1]));
        $renewed = $auth2->loginFromRememberCookie($cookie, '127.0.0.1', 'test');
        assert_true($renewed !== null && $renewed[1] !== $cookie, 'rotated');
        assert_same($id, $auth2->id());
        // Replaying the old cookie fails (it was consumed)...
        $auth3 = new Auth($db, new Session(sys_get_temp_dir(), ['name' => 't3', 'idle_minutes' => 5, 'absolute_hours' => 1]));
        assert_same(null, $auth3->loginFromRememberCookie($cookie, '127.0.0.1', 'test'));
        // ...and a right selector with a wrong validator (theft) revokes every token of the user.
        [$selector] = explode(':', $renewed[1]);
        assert_same(null, $auth3->loginFromRememberCookie($selector . ':' . str_repeat('0', 64), '127.0.0.1', 'test'));
        assert_same(0, (int) $db->scalar('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$id]));
    });
});

test('points: balance never goes negative', function () use ($withUser) {
    $withUser(function (int $id) {
        $points = new Points(app()->db());
        assert_true($points->add($id, 30, 'admin'));
        assert_false($points->add($id, -31, 'redeem'), 'cannot overspend');
        assert_true($points->add($id, -30, 'redeem'));
        assert_same(0, $points->balance($id));
        assert_same(10, Points::value(1000) / 100, '1000 points = $10.00');
    });
});

test('login: wrong passwords lock out after 5 attempts (per account), even with the right password', function () use ($withUser) {
    $withUser(function (int $id, string $email) {
        $post = static fn (string $pw) => app()->handle(Request::create('POST', '/my-account/login', ['_token' => app()->csrf()->token(), 'username' => $email, 'password' => $pw], ['REMOTE_ADDR' => '10.9.' . random_int(0, 250) . '.' . random_int(1, 250)]));
        for ($i = 0; $i < 5; $i++) {
            $post('wrong-password-' . $i);
        }
        assert_true(app()->limiter()->tooManyAttempts('login:id:' . strtolower($email), 5), 'account is locked');
        $res = $post('a-long-password');
        assert_same(303, $res->status());
        assert_true(app()->auth()->id() !== $id, 'correct password is refused while locked');
    });
});

test('login redirect: only same-site paths are honoured', function () {
    $ctl = new App\Http\Controllers\AuthController(app());
    $method = new ReflectionMethod($ctl, 'safeRedirect');
    $method->setAccessible(true);
    assert_same('/checkout/', $method->invoke($ctl, '/checkout/'));
    assert_same(null, $method->invoke($ctl, '//evil.com/'));
    assert_same(null, $method->invoke($ctl, 'https://evil.com/'));
    assert_same(null, $method->invoke($ctl, '/\\evil.com'));
});

test('account pages require login and orders are private', function () {
    $res = app()->handle(Request::create('GET', '/my-account/orders/'));
    assert_same(302, $res->status());
    assert_contains('/my-account/', (string) $res->getHeader('Location'));
});
