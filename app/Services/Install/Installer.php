<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Core\Database;

/**
 * First-time setup (the /install/ wizard): is the app installed yet, does the
 * server meet the requirements, writing .env safely, the one-time setup code,
 * and checking a database before it is saved.
 *
 * "Installed" = storage/installed exists. Sites set up by hand (a working
 * .env, migrated database and an admin account) get that file automatically.
 */
final class Installer
{
    public const LOCK = 'storage/installed';
    public const CODE = 'storage/setup-code.txt';

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function isInstalled(): bool
    {
        if (is_file($this->root . '/' . self::LOCK)) {
            return true;
        }
        // Once the wizard has been started, only its Finish step completes the setup
        // (an uploaded database may already have admins while the domain is not set yet).
        if (is_file($this->root . '/' . self::CODE)) {
            return false;
        }
        // A site set up by hand: working .env, domain configured, migrated database with an admin.
        if (!is_file($this->root . '/.env') || strlen((string) \App\Core\Env::get('APP_KEY', '')) < 32
            || ((string) \App\Core\Env::get('TRUSTED_HOSTS', '') === '' && \App\Core\Env::get('APP_ENV', 'production') !== 'local')) {
            return false;
        }
        try {
            $db = app()->db();
            if (!$db->scalar("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles'")
                || !$db->scalar("SELECT 1 FROM user_roles WHERE role = 'admin' LIMIT 1")) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        $this->markInstalled('detected an existing setup');
        return true;
    }

    public function markInstalled(string $how): void
    {
        @file_put_contents($this->root . '/' . self::LOCK, json_encode(['installed_at' => gmdate('c'), 'how' => $how]) . "\n");
        @unlink($this->root . '/' . self::CODE);
    }

    /**
     * The code that unlocks the wizard. It is written to a file only someone
     * with access to the server can read (hPanel File Manager or SSH).
     */
    public function setupCode(): string
    {
        $file = $this->root . '/' . self::CODE;
        $code = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (!preg_match('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $code)) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $parts = [];
            for ($p = 0; $p < 4; $p++) {
                $chunk = '';
                for ($i = 0; $i < 4; $i++) {
                    $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $parts[] = $chunk;
            }
            $code = implode('-', $parts);
            @file_put_contents($file, $code . "\n");
            @chmod($file, 0600);
        }
        return $code;
    }

    public function checkCode(string $given): bool
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $given));
        return $normalized !== '' && hash_equals(str_replace('-', '', $this->setupCode()), $normalized);
    }

    /**
     * Server requirements. Each row: [status PASS|WARN|FAIL, label, fix].
     * @return array<int, array{0:string,1:string,2:string}>
     */
    public function requirements(): array
    {
        $rows = [];
        $rows[] = [version_compare(PHP_VERSION, '8.1.0', '>=') ? 'PASS' : 'FAIL', 'PHP ' . PHP_VERSION, 'needs 8.1 or newer (hPanel → PHP Configuration)'];
        foreach (['pdo_mysql', 'sodium', 'gd', 'mbstring', 'curl', 'dom', 'fileinfo', 'zlib', 'openssl', 'json', 'zip', 'intl'] as $ext) {
            $optional = in_array($ext, ['intl', 'zip'], true);
            $rows[] = [extension_loaded($ext) ? 'PASS' : ($optional ? 'WARN' : 'FAIL'), 'PHP extension ' . $ext, 'turn it on in hPanel → PHP Configuration → PHP extensions'];
        }
        if (function_exists('gd_info')) {
            $rows[] = [!empty(gd_info()['WebP Support']) ? 'PASS' : 'FAIL', 'Image library can write WebP', 'GD needs WebP support for product images'];
        }
        foreach (['storage', 'storage/cache', 'storage/logs', 'storage/sessions', 'storage/mail', 'public/uploads', 'public/assets/build'] as $dir) {
            $path = $this->root . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $rows[] = [is_dir($path) && is_writable($path) ? 'PASS' : 'FAIL', $dir . '/ is writable', 'set folder permissions to 755 (or 775) in File Manager'];
        }
        $envWritable = is_file($this->root . '/.env') ? is_writable($this->root . '/.env') : is_writable($this->root);
        $rows[] = [$envWritable ? 'PASS' : 'WARN', 'Settings file (.env) can be saved', 'otherwise you will be shown its contents to create it yourself'];
        $rows[] = [is_file($this->root . '/public/uploads/.htaccess') ? 'PASS' : 'FAIL', 'Upload folder protection present', 'upload public/uploads/.htaccess from the package'];
        $upload = (string) ini_get('upload_max_filesize');
        $rows[] = [(int) $upload >= 10 || str_ends_with(strtoupper($upload), 'G') ? 'PASS' : 'WARN', 'Uploads up to ' . $upload, 'set upload_max_filesize to 16M for 10 MB product images'];
        return $rows;
    }

    public static function hasFailures(array $rows): bool
    {
        return in_array('FAIL', array_column($rows, 0), true);
    }

    /**
     * Try a database login before saving it.
     * @return array{version:string, mariadb:bool, tables:int, admins:int, products:int}
     * @throws \RuntimeException with a readable message
     */
    public function testDatabase(array $cfg): array
    {
        try {
            $db = new Database(array_merge((array) config('database'), $cfg));
            $version = (string) $db->scalar('SELECT VERSION()');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $hint = match (true) {
                str_contains($msg, 'Access denied') => 'The username or password is wrong, or this user has no access to the database.',
                str_contains($msg, 'Unknown database') => 'That database does not exist. Create it in hPanel → Databases first.',
                str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused') || str_contains($msg, 'timed out') => 'Cannot reach the database server. On Hostinger the host is usually "localhost".',
                default => 'The database refused the connection.',
            };
            throw new \RuntimeException($hint);
        }
        if ($db->scalar("SELECT JSON_UNQUOTE(JSON_EXTRACT('{\"a\":\"ok\"}', '$.a'))") !== 'ok') {
            throw new \RuntimeException('This database server does not support JSON functions (MySQL 5.7+ or MariaDB 10.2+ is needed).');
        }
        $has = static fn (string $t) => (bool) $db->scalar('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
        return [
            'version' => $version,
            'mariadb' => stripos($version, 'mariadb') !== false,
            'tables' => (int) $db->scalar('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
            'admins' => $has('user_roles') ? (int) $db->scalar("SELECT COUNT(*) FROM user_roles WHERE role = 'admin'") : 0,
            'products' => $has('products') ? (int) $db->scalar('SELECT COUNT(*) FROM products') : 0,
        ];
    }

    /**
     * Set values in .env (created from .env.example when missing), keeping
     * every other line and comment as it is.
     * @return string the new file contents (also written when possible)
     * @throws \InvalidArgumentException for a value that cannot be stored safely
     */
    public function writeEnv(array $values, bool $write = true): string
    {
        $file = $this->root . '/.env';
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : (is_file($this->root . '/.env.example') ? file($this->root . '/.env.example', FILE_IGNORE_NEW_LINES) : []);
        $pending = $values;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m) && array_key_exists($m[1], $pending)) {
                $lines[$i] = $m[1] . '=' . self::quote((string) $pending[$m[1]]);
                unset($pending[$m[1]]);
            }
        }
        foreach ($pending as $key => $value) {
            $lines[] = $key . '=' . self::quote((string) $value);
        }
        $content = implode("\n", $lines) . "\n";
        if ($write) {
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
            if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Could not save .env');
            }
            @chmod($file, 0600);
        }
        return $content;
    }

    /** Quote a value so Env::load() reads back exactly the same string. */
    public static function quote(string $value): string
    {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new \InvalidArgumentException('Values cannot contain line breaks.');
        }
        $literal = in_array(strtolower($value), ['true', 'false', 'null'], true); // would otherwise be read as a boolean/null
        if ($value === '' || (!$literal && preg_match('/^[A-Za-z0-9_.,:\/@+*-]+$/', $value))) {
            return $value;
        }
        if (!str_contains($value, "'")) {
            return "'" . $value . "'"; // single quotes: taken literally (no ${} or # handling)
        }
        if (!str_contains($value, '"') && !str_contains($value, '${')) {
            return '"' . $value . '"';
        }
        throw new \InvalidArgumentException('This value contains both kinds of quotes; please choose one without \' or ".');
    }

    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
