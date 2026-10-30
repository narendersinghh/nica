<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Security\PasswordHasher;
use App\Services\Updater\UpdateException;
use App\Services\Updater\Updater;

/** A throw-away "installed app" folder and helpers to build GitHub-style zips. */
$fixture = static function (callable $fn): void {
    $root = sys_get_temp_dir() . '/nica-upd-' . bin2hex(random_bytes(4));
    $files = [
        '.env' => "APP_KEY=secret\n",
        'app/bootstrap.php' => '<?php // v1',
        'app/old.php' => '<?php // removed in v2',
        'public/index.php' => '<?php // v1',
        'public/.htaccess' => "# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler x .php\n# php -- END cPanel-generated handler, do not edit\n\nRewriteEngine On\n",
        'public/google123.html' => 'verification',
        'public/uploads/photo.webp' => 'IMG',
        'bin/console' => '<?php // v1',
        'routes/web.php' => '<?php // v1',
        'storage/logs/app.log' => 'LOG',
    ];
    foreach ($files as $path => $content) {
        @mkdir(dirname($root . '/' . $path), 0777, true);
        file_put_contents($root . '/' . $path, $content);
    }
    foreach (['config', 'database/migrations'] as $d) {
        @mkdir($root . '/' . $d, 0777, true);
    }
    $zip = static function (array $entries, string $prefix = 'narendersinghh-nica-abc1234/') use ($root): string {
        $file = $root . '/../pkg-' . bin2hex(random_bytes(3)) . '.zip';
        $z = new ZipArchive();
        $z->open($file, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $z->addFromString($prefix . $name, $content);
        }
        $z->close();
        return $file;
    };
    try {
        $fn($root, $zip);
    } finally {
        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg(dirname($root)) . '/pkg-*.zip');
    }
};
$v2 = [
    'app/bootstrap.php' => '<?php // v2',
    'app/new.php' => '<?php // added in v2',
    'public/index.php' => '<?php // v2',
    'public/.htaccess' => "RewriteEngine On\n# v2\n",
    'bin/console' => '<?php // v2',
    'routes/web.php' => '<?php // v2',
    '.env' => 'APP_KEY=attacker',                 // must be ignored
    'storage/evil.php' => '<?php',              // must be ignored
    'public/uploads/photo.webp' => 'REPLACED',  // must be ignored
];

test('updater: installs a GitHub package, keeps data, hosting lines and custom files', function () use ($fixture, $v2) {
    $fixture(function (string $root, callable $zip) use ($v2) {
        $u = new Updater($root);
        $u->apply($zip($v2), ['type' => 'commit', 'ref' => 'main@abc1234', 'sha' => str_repeat('a', 40), 'by' => 'test']);
        assert_same('<?php // v2', file_get_contents($root . '/app/bootstrap.php'));
        assert_true(is_file($root . '/app/new.php'));
        assert_false(is_file($root . '/app/old.php'), 'code dropped by the new version is removed');
        assert_same('verification', file_get_contents($root . '/public/google123.html'), 'custom public files stay');
        assert_same("APP_KEY=secret\n", file_get_contents($root . '/.env'));
        assert_same('IMG', file_get_contents($root . '/public/uploads/photo.webp'));
        assert_false(is_file($root . '/storage/evil.php'));
        $ht = (string) file_get_contents($root . '/public/.htaccess');
        assert_contains('# v2', $ht);
        assert_same(1, substr_count($ht, 'BEGIN cPanel-generated handler'), 'hosting block kept once');
        assert_true(is_file($root . '/storage/maintenance'), 'maintenance during the switch');
        assert_true($u->installed()['pending_finish']);
        assert_same(1, count($u->backups()));

        $ran = $u->finish(fn () => ['m1'], fn () => null);
        assert_same(['m1'], $ran);
        assert_false(is_file($root . '/storage/maintenance'));
        assert_false(isset($u->installed()['pending_finish']));

        // Roll back: v1 code is back, data still untouched, hosting block still once.
        $u->rollback('test');
        $u->finish(fn () => [], fn () => null);
        assert_same('<?php // v1', file_get_contents($root . '/app/bootstrap.php'));
        assert_true(is_file($root . '/app/old.php'));
        assert_false(is_file($root . '/app/new.php'));
        assert_same(1, substr_count((string) file_get_contents($root . '/public/.htaccess'), 'BEGIN cPanel-generated handler'));
        assert_same("APP_KEY=secret\n", file_get_contents($root . '/.env'));
        assert_same('rollback', $u->installed()['type']);
    });
});

test('updater: refuses unsafe or foreign packages and git checkouts', function () use ($fixture, $v2) {
    $fixture(function (string $root, callable $zip) use ($v2) {
        $u = new Updater($root);
        $bad = [
            'path traversal' => $zip($v2 + ['../../outside.php' => 'x']),
            'not this app' => $zip(['readme.md' => 'hello']),
        ];
        // Two top-level folders (not a GitHub zipball).
        $mixed = $root . '/../pkg-mixed.zip';
        $z = new ZipArchive();
        $z->open($mixed, ZipArchive::CREATE);
        foreach ($v2 as $n => $c) {
            $z->addFromString('a/' . $n, $c);
        }
        $z->addFromString('b/extra.php', 'x');
        $z->close();
        $bad['two top folders'] = $mixed;
        // A symbolic link entry.
        $link = $zip($v2);
        $z = new ZipArchive();
        $z->open($link);
        $z->addFromString('narendersinghh-nica-abc1234/app/link.php', '/etc/passwd');
        $z->setExternalAttributesName('narendersinghh-nica-abc1234/app/link.php', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $z->close();
        $bad['symlink'] = $link;
        foreach ($bad as $label => $file) {
            assert_throws(UpdateException::class, fn () => $u->apply($file, ['ref' => 'x']), $label);
        }
        assert_same('<?php // v1', file_get_contents($root . '/app/bootstrap.php'), 'nothing changed');
        assert_false(is_file($root . '/storage/maintenance'));

        mkdir($root . '/.git');
        assert_true(str_contains(implode(' ', $u->preflight()), 'git checkout'));
    });
    assert_true(Updater::isProtected('.env') && Updater::isProtected('storage/x') && Updater::isProtected('public/uploads/a.webp') && Updater::isProtected('dump.sql'));
    assert_false(Updater::isProtected('.env.example'));
    assert_false(Updater::isRemovable('public/google123.html'));
    assert_true(Updater::isRemovable('app/Http/Old.php'));
});

test('updates: maintenance page for visitors, the updater and stale flags pass; staff cannot update', function () {
    $flag = app()->path('storage/maintenance');
    file_put_contents($flag, (string) time());
    try {
        $res = app()->handle(Request::create('GET', '/about/'));
        assert_same(503, $res->status());
        assert_same('60', $res->getHeader('Retry-After'));
        assert_true(app()->handle(Request::create('GET', '/admin/updates/'))->status() !== 503, 'updater reachable');
        touch($flag, time() - 3600);
        assert_same(200, app()->handle(Request::create('GET', '/about/'))->status(), 'stale flag ignored');
    } finally {
        @unlink($flag);
    }
    $db = app()->db();
    $tag = bin2hex(random_bytes(3));
    $id = $db->insert('users', ['email' => "upd+{$tag}@nica.test", 'username' => "upd{$tag}", 'password_hash' => PasswordHasher::hash('a-long-password'), 'display_name' => 'U']);
    $db->insert('user_roles', ['user_id' => $id, 'role' => 'staff']);
    app()->auth()->login($db->first('SELECT * FROM users WHERE id = ?', [$id]), '127.0.0.1', false);
    try {
        assert_same(403, app()->handle(Request::create('GET', '/admin/updates/'))->status());
        assert_same(403, app()->handle(Request::create('POST', '/admin/updates/install', ['_token' => app()->csrf()->token(), 'target' => 'commit', 'sha' => str_repeat('a', 40)]))->status());
    } finally {
        app()->auth()->logout();
        $db->delete('users', ['id' => $id]);
    }
});
