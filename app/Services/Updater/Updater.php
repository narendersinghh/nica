<?php

declare(strict_types=1);

namespace App\Services\Updater;

/**
 * Replaces this app's code with a version downloaded from GitHub.
 *
 *   apply()    validate the zip → back up the current code → maintenance mode →
 *              copy files → remove files the new version no longer has
 *   finish()   (runs on the NEW code) migrations, caches, maintenance off
 *   rollback() the same as apply(), from the latest backup
 *
 * Never touched: .env, storage/, public/uploads/, public/assets/build/, .git,
 * local tooling and database dumps. Hosting lines in .htaccess (e.g. the PHP
 * version handler Hostinger adds) are carried over into the new .htaccess.
 */
final class Updater
{
    private const MAX_FILES = 5000;
    private const MAX_UNPACKED_BYTES = 200 * 1024 * 1024;
    /** Must exist in every valid package. */
    private const REQUIRED = ['app/bootstrap.php', 'public/index.php', 'bin/console', 'routes/web.php'];

    private string $dir;

    public function __construct(private string $root, ?string $stateDir = null)
    {
        $this->root = rtrim($root, '/');
        $this->dir = rtrim($stateDir ?? $this->root . '/storage/updates', '/');
    }

    /** Paths the updater must never write or delete (relative, forward slashes). */
    public static function isProtected(string $path): bool
    {
        return (bool) preg_match('#^(\.env(\..*)?$|storage/|public/uploads/|public/assets/build/|\.git(/|$)|\.devenv/|node_modules/)|\.sql$#', $path)
            && !preg_match('#^\.env\.example$#', $path);
    }

    /** Only code that ships with the app may be deleted when a new version drops it. */
    public static function isRemovable(string $path): bool
    {
        return !self::isProtected($path)
            && (bool) preg_match('#^(app|bin|config|database|routes|tests|public/assets/(css|js|fonts))/#', $path);
    }

    public function isGitCheckout(): bool
    {
        return is_dir($this->root . '/.git');
    }

    /** @return array{type?:string, ref?:string, sha?:string, installed_at?:string, by?:string, pending_finish?:bool} */
    public function installed(): array
    {
        $data = is_file($this->dir . '/installed.json') ? json_decode((string) file_get_contents($this->dir . '/installed.json'), true) : null;
        return is_array($data) ? $data : [];
    }

    public function maintenanceFlag(): string
    {
        return $this->root . '/storage/maintenance';
    }

    /** Problems that block updating from the web (empty = good to go). */
    public function preflight(): array
    {
        $problems = [];
        if ($this->isGitCheckout()) {
            $problems[] = 'This copy of the app is a git checkout. Update it with "git pull" instead (web updates would overwrite your working copy).';
        }
        if (!class_exists(\ZipArchive::class)) {
            $problems[] = 'The PHP zip extension is required.';
        }
        foreach (['', 'app', 'public', 'routes', 'config', 'database/migrations'] as $d) {
            if (!is_writable($this->root . ($d === '' ? '' : '/' . $d))) {
                $problems[] = 'PHP cannot write to ' . ($d === '' ? 'the app folder' : $d . '/') . '.';
                break;
            }
        }
        return $problems;
    }

    public function backups(): array
    {
        $list = glob($this->dir . '/backup-*.zip') ?: [];
        rsort($list);
        return array_map(static function (string $f) {
            $meta = json_decode((string) @file_get_contents(substr($f, 0, -4) . '.json'), true) ?: [];
            return ['file' => basename($f), 'size' => (int) filesize($f), 'created' => (int) filemtime($f)] + $meta;
        }, $list);
    }

    /**
     * Install a downloaded package. $meta describes it (type, ref, sha, by).
     * After this returns, call finish() from a NEW request so the new code runs it.
     */
    public function apply(string $zipFile, array $meta, bool $githubLayout = true): void
    {
        $this->ensureDir();
        $lock = fopen($this->dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new UpdateException('Another update is running. Try again in a minute.');
        }
        $staging = $this->dir . '/staging-' . bin2hex(random_bytes(4));
        try {
            $files = $this->extract($zipFile, $staging, $githubLayout);
            $backup = $this->backup($meta);
            @file_put_contents($this->maintenanceFlag(), (string) time());
            try {
                $this->install($staging, $files);
            } catch (\Throwable $e) {
                // Half-copied code is worse than old code: put the backup straight back.
                $restore = $this->extractBackup($backup);
                $this->install($restore, $this->readManifest($backup));
                $this->removeTree($restore);
                @unlink($this->maintenanceFlag());
                throw new UpdateException('The update could not be copied (' . $e->getMessage() . '). The previous version was restored.');
            }
            // Files the new version no longer has are removed, but only inside the app's own
            // code folders (a file you added to public/, e.g. a site-verification file, stays).
            // Before the first update there is no file list yet: the backup's list stands in.
            $old = $this->manifest() ?: $this->readManifest($backup);
            foreach (array_diff($old, $files) as $gone) {
                if (self::isRemovable($gone) && is_file($this->root . '/' . $gone)) {
                    @unlink($this->root . '/' . $gone);
                }
            }
            $this->writeJson('manifest.json', array_values($files));
            $this->writeJson('installed.json', $meta + ['installed_at' => gmdate('c'), 'pending_finish' => true, 'previous' => $this->installed()['sha'] ?? null]);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        } finally {
            $this->removeTree($staging);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Second step, run by the new code: database migrations, fresh caches,
     * maintenance off. Returns the migrations that ran.
     */
    public function finish(callable $migrate, callable $clearCaches): array
    {
        try {
            $ran = $migrate();
            $clearCaches();
            $state = $this->installed();
            unset($state['pending_finish']);
            $state['finished_at'] = gmdate('c');
            $this->writeJson('installed.json', $state);
            return $ran;
        } finally {
            @unlink($this->maintenanceFlag());
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        }
    }

    /** Put back the most recent backup (code only; the database is kept). */
    public function rollback(string $by): array
    {
        $backups = $this->backups();
        if (!$backups) {
            throw new UpdateException('There is no backup to restore.');
        }
        $latest = $backups[0];
        $file = $this->dir . '/' . $latest['file'];
        $restoredMeta = ['type' => 'rollback', 'ref' => $latest['ref'] ?? 'previous', 'sha' => $latest['sha'] ?? null, 'by' => $by];
        $this->apply($file, $restoredMeta, false);
        // The backup was used; keep the one just made of the version we rolled back from instead.
        @unlink($file);
        @unlink(substr($file, 0, -4) . '.json');
        return $latest;
    }

    /* ---------------------------------------------------------------- internals */

    /**
     * Unpack into $staging after checking every entry. GitHub zips wrap
     * everything in one "owner-repo-sha/" folder, which is stripped.
     * @return string[] relative file paths that will be installed
     */
    private function extract(string $zipFile, string $staging, bool $githubLayout): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new UpdateException('The download is not a valid zip file.');
        }
        if ($zip->numFiles > self::MAX_FILES) {
            throw new UpdateException('The package has too many files.');
        }
        $prefix = null;
        $total = 0;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];
            $zip->getExternalAttributesIndex($i, $os, $attr);
            if ($os === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new UpdateException('The package contains a symbolic link (' . $name . ').');
            }
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new UpdateException('The package contains an unsafe path (' . $name . ').');
            }
            if ($githubLayout) {
                $first = explode('/', $name, 2)[0];
                $prefix ??= $first;
                if ($first !== $prefix) {
                    throw new UpdateException('Unexpected package layout.');
                }
                $name = substr($name, strlen($prefix) + 1);
            }
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $total += (int) $stat['size'];
            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new UpdateException('The package is too large.');
            }
            if (self::isProtected($name) || $name === '.nica-manifest.json') {
                continue; // a package can never overwrite .env, storage, uploads…
            }
            $entries[$i] = $name;
        }
        foreach (self::REQUIRED as $req) {
            if (!in_array($req, $entries, true)) {
                $zip->close();
                throw new UpdateException('This does not look like a NiCa package (missing ' . $req . ').');
            }
        }
        foreach ($entries as $i => $name) {
            $target = $staging . '/' . $name;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new UpdateException('Cannot unpack the package.');
            }
            $stream = $zip->getStream((string) $zip->getNameIndex($i));
            $out = fopen($target, 'wb');
            if ($stream === false || $out === false || stream_copy_to_stream($stream, $out) === false) {
                throw new UpdateException('Cannot unpack ' . $name . '.');
            }
            fclose($stream);
            fclose($out);
        }
        $zip->close();
        sort($entries);
        return array_values($entries);
    }

    /** Copy staged files over the app, one atomic rename per file. */
    private function install(string $staging, array $files): void
    {
        foreach ($files as $rel) {
            if (self::isProtected($rel)) {
                continue;
            }
            $src = $staging . '/' . $rel;
            $dst = $this->root . '/' . $rel;
            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst), 0775, true) && !is_dir(dirname($dst))) {
                throw new \RuntimeException('cannot create ' . dirname($rel));
            }
            $content = (string) file_get_contents($src);
            if (basename($rel) === '.htaccess' && is_file($dst)) {
                $content = self::keepHostingBlocks((string) file_get_contents($dst), $content);
            }
            $tmp = $dst . '.upd-' . bin2hex(random_bytes(3));
            if (file_put_contents($tmp, $content) === false || !rename($tmp, $dst)) {
                @unlink($tmp);
                throw new \RuntimeException('cannot write ' . $rel);
            }
            if ($rel === 'bin/console') {
                @chmod($dst, 0755);
            }
        }
    }

    /**
     * Hosting panels add marked blocks to .htaccess (e.g. "# php -- BEGIN
     * cPanel-generated handler … # php -- END …"). Keep them on top of the new file.
     */
    public static function keepHostingBlocks(string $old, string $new): string
    {
        preg_match_all('/^#[ \t]*(?:php -- )?BEGIN\b[^\n]*\n.*?^#[ \t]*(?:php -- )?END\b[^\n]*$/msi', $old, $m);
        $keep = array_filter($m[0], static fn ($block) => !str_contains($new, trim($block)));
        return $keep ? implode("\n\n", $keep) . "\n\n" . $new : $new;
    }

    /** Zip of the code as it is now (everything the updater could overwrite). */
    private function backup(array $meta): string
    {
        $current = $this->installed();
        $name = 'backup-' . gmdate('Ymd-His') . '-' . substr((string) ($current['sha'] ?? 'unknown'), 0, 7);
        $file = $this->dir . '/' . $name . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('Cannot create a backup in storage/updates/.');
        }
        $files = [];
        $root = $this->root;
        // Skip protected folders entirely (uploads alone can be gigabytes).
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f) => !self::isProtected(str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)) . ($f->isDir() ? '/' : ''))
        );
        $it = new \RecursiveIteratorIterator($filter);
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($this->root) + 1));
            if (self::isProtected($rel) || str_contains($rel, '.upd-')) {
                continue;
            }
            $zip->addFile($f->getPathname(), $rel);
            $files[] = $rel;
        }
        $zip->addFromString('.nica-manifest.json', json_encode($files));
        $zip->close();
        $this->writeJson($name . '.json', ['ref' => $current['ref'] ?? 'unknown', 'sha' => $current['sha'] ?? null, 'type' => $current['type'] ?? 'manual', 'replaced_by' => $meta['ref'] ?? null]);
        // Keep only the newest few.
        foreach (array_slice($this->backups(), (int) config('updates.keep_backups', 3)) as $old) {
            @unlink($this->dir . '/' . $old['file']);
            @unlink($this->dir . '/' . substr($old['file'], 0, -4) . '.json');
        }
        return $file;
    }

    private function extractBackup(string $file): string
    {
        $staging = $this->dir . '/restore-' . bin2hex(random_bytes(4));
        $this->extract($file, $staging, false);
        return $staging;
    }

    private function readManifest(string $backupZip): array
    {
        $zip = new \ZipArchive();
        $zip->open($backupZip);
        $list = json_decode((string) $zip->getFromName('.nica-manifest.json'), true);
        $zip->close();
        return is_array($list) ? $list : [];
    }

    /** Files installed by the previous update (empty before the first one). */
    private function manifest(): array
    {
        $list = is_file($this->dir . '/manifest.json') ? json_decode((string) file_get_contents($this->dir . '/manifest.json'), true) : [];
        return is_array($list) ? $list : [];
    }

    private function writeJson(string $name, array $data): void
    {
        $this->ensureDir();
        file_put_contents($this->dir . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new UpdateException('Cannot create storage/updates/.');
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
