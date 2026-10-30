<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Http\Request;
use App\Services\Install\Installer;

$tempRoot = static function (callable $fn): void {
    $root = sys_get_temp_dir() . '/nica-setup-' . bin2hex(random_bytes(4));
    mkdir($root . '/storage', 0777, true);
    file_put_contents($root . '/.env.example', "# Example\nAPP_NAME=\"The NiCa Collection\"\nAPP_KEY=\nDB_HOST=127.0.0.1\nDB_PASSWORD=\n# keep me\nMAIL_FROM_NAME=\"\${APP_NAME}\"\n");
    try {
        $fn($root, new Installer($root));
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
};

test('setup: .env values are written so they read back exactly', function () use ($tempRoot) {
    $tempRoot(function (string $root, Installer $installer) {
        $tricky = [
            'DB_PASSWORD' => 'p@ss w#rd $HOME ${APP_KEY} "q"',
            'MAIL_PASSWORD' => "it's \$ecret #1",
            'STRIPE_SECRET_KEY' => 'sk_test_ABC123',
            'APP_ENV' => 'production',
            'WEIRD' => 'true',
            'EMPTY' => '',
            'TRUSTED_HOSTS' => 'thenicacollection.com,www.thenicacollection.com',
        ];
        $installer->writeEnv($tricky);
        $read = Env::read($root . '/.env');
        foreach ($tricky as $k => $v) {
            assert_same($v, $read[$k], $k . ' round-trips');
        }
        $content = (string) file_get_contents($root . '/.env');
        assert_contains('# keep me', $content, 'comments from .env.example are kept');
        assert_contains('MAIL_FROM_NAME="${APP_NAME}"', $content);
        assert_same(1, substr_count($content, 'DB_PASSWORD='), 'existing key replaced, not duplicated');
        assert_same('0600', substr(sprintf('%o', fileperms($root . '/.env')), -4));
        // Updating one value keeps the others.
        $installer->writeEnv(['APP_ENV' => 'staging']);
        assert_same('staging', Env::read($root . '/.env')['APP_ENV']);
        assert_same($tricky['DB_PASSWORD'], Env::read($root . '/.env')['DB_PASSWORD']);
        assert_throws(InvalidArgumentException::class, fn () => Installer::quote("two\nlines"));
        assert_throws(InvalidArgumentException::class, fn () => Installer::quote('both \' and "'));
        assert_same(64, strlen(Installer::generateKey()));
    });
    assert_true(Env::get('DB_DATABASE') !== null, 'reading a file never changes the loaded settings');
});

test('setup: one-time code, install detection and requirements', function () use ($tempRoot) {
    $tempRoot(function (string $root, Installer $installer) {
        assert_false($installer->isInstalled(), 'no .env yet');
        $code = $installer->setupCode();
        assert_true((bool) preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $code));
        assert_same($code, $installer->setupCode(), 'stable until used');
        assert_same('0600', substr(sprintf('%o', fileperms($root . '/' . Installer::CODE)), -4));
        assert_true($installer->checkCode(strtolower(str_replace('-', ' ', $code))), 'case and separators do not matter');
        assert_false($installer->checkCode('AAAA-AAAA-AAAA-AAAA'));
        assert_false($installer->checkCode(''));
        file_put_contents($root . '/.env', "APP_KEY=" . str_repeat('a', 64) . "\nTRUSTED_HOSTS=example.com\n");
        assert_false($installer->isInstalled(), 'a started wizard is never auto-completed, whatever the database holds');
        $installer->markInstalled('test');
        assert_true($installer->isInstalled());
        assert_false(is_file($root . '/' . Installer::CODE), 'code deleted after setup');
        $rows = $installer->requirements();
        assert_true(count($rows) > 10);
        assert_true(in_array($rows[0][0], ['PASS', 'FAIL'], true));
    });
});

test('setup: wizard is gone once installed; database errors are readable', function () {
    assert_true(app()->isInstalled());
    assert_same(404, app()->handle(Request::create('GET', '/install/'))->status());
    assert_same(404, app()->handle(Request::create('POST', '/install/unlock', ['_token' => app()->csrf()->token(), 'code' => 'x']))->status());
    $installer = new Installer(app()->path());
    try {
        $installer->testDatabase(['password' => 'definitely-wrong-password']);
        assert_true(false, 'should fail');
    } catch (RuntimeException $e) {
        assert_contains('username or password', $e->getMessage());
    }
    $info = $installer->testDatabase([]);
    assert_true($info['tables'] > 10 && $info['admins'] > 0);
});
