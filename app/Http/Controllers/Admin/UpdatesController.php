<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Migrator;
use App\Services\Audit;
use App\Services\Cache\PageCache;
use App\Services\Updater\GitHubSource;
use App\Services\Updater\UpdateException;
use App\Services\Updater\Updater;

/** Admin → Updates: install a GitHub release or the latest commit, and roll back. */
final class UpdatesController extends AdminController
{
    private const CHECK_TTL = 300;

    public function index(Request $request): Response
    {
        $updater = $this->updater();
        $installed = $updater->installed();
        $remote = null;
        $error = null;
        try {
            $remote = $this->remote($request->query('refresh') === '1');
        } catch (UpdateException $e) {
            $error = $e->getMessage();
        }
        $changes = ['release' => null, 'commit' => null];
        if ($remote && !empty($installed['sha'])) {
            try {
                $source = GitHubSource::fromConfig();
                foreach (['release' => $remote['release']['sha'] ?? null, 'commit' => $remote['head']['sha'] ?? null] as $k => $sha) {
                    if ($sha && $sha !== $installed['sha']) {
                        $changes[$k] = $source->compare((string) $installed['sha'], $sha);
                    }
                }
            } catch (UpdateException) {
                // the list of changes is a nice-to-have
            }
        }
        return $this->panel($request, 'admin/updates', [
            'title' => 'Updates',
            'tab' => 'updates',
            'installed' => $installed,
            'remote' => $remote,
            'changes' => $changes,
            'error' => $error,
            'problems' => $updater->preflight(),
            'backups' => $updater->backups(),
            'repository' => (string) config('updates.repository'),
            'branch' => (string) config('updates.branch'),
        ]);
    }

    public function install(Request $request): Response
    {
        $target = (string) $request->post('target');
        $sha = strtolower((string) $request->post('sha'));
        $updater = $this->updater();
        try {
            if ($problems = $updater->preflight()) {
                throw new UpdateException($problems[0]);
            }
            // Re-check with GitHub: install exactly the version the admin saw, nothing else.
            $remote = $this->remote(true);
            $expected = $target === 'release' ? ($remote['release']['sha'] ?? null) : ($target === 'commit' ? ($remote['head']['sha'] ?? null) : null);
            if ($expected === null || !hash_equals($expected, $sha)) {
                throw new UpdateException('GitHub changed since you opened this page. Please check again.');
            }
            $ref = $target === 'release' ? (string) $remote['release']['tag'] : $remote['branch'] . '@' . substr($sha, 0, 7);
            $zip = $this->app->path('storage/updates/download-' . $sha . '.zip');
            @mkdir(dirname($zip), 0770, true);
            GitHubSource::fromConfig()->download($sha, $zip);
            try {
                $updater->apply($zip, ['type' => $target, 'ref' => $ref, 'sha' => $sha, 'by' => (string) $this->app->auth()->user()['email']]);
            } finally {
                @unlink($zip);
            }
            Audit::log('app.update_installed', null, null, ['ref' => $ref, 'sha' => $sha]);
        } catch (UpdateException $e) {
            Audit::log('app.update_failed', null, null, ['target' => $target, 'error' => $e->getMessage()]);
            $this->error($e->getMessage());
            return $this->back('/admin/updates/');
        }
        return $this->finishing($request, 'Installing ' . $ref);
    }

    /** Runs on the NEW code (a fresh request after the files were replaced). */
    public function finish(Request $request): Response
    {
        $updater = $this->updater();
        if (empty($updater->installed()['pending_finish'])) {
            return $this->back('/admin/updates/');
        }
        try {
            $ran = $updater->finish(
                fn () => (new Migrator($this->app->db(), $this->app->path('database/migrations')))->migrate(),
                fn () => $this->clearCaches(),
            );
            $installed = $updater->installed();
            Audit::log('app.update_finished', null, null, ['ref' => $installed['ref'] ?? null, 'migrations' => $ran]);
            $this->notice('Updated to ' . ($installed['ref'] ?? 'the new version') . '.' . ($ran ? ' Database changes applied: ' . count($ran) . '.' : ''));
        } catch (\Throwable $e) {
            $this->app->logger()->error('Update finish failed', ['error' => $e->getMessage()]);
            $this->error('The new code is installed but finishing failed: ' . $e->getMessage() . ' You can roll back below.');
        }
        return $this->back('/admin/updates/');
    }

    public function rollback(Request $request): Response
    {
        $updater = $this->updater();
        try {
            if ($updater->isGitCheckout()) {
                throw new UpdateException('This copy is a git checkout. Use git to go back.');
            }
            $restored = $updater->rollback((string) $this->app->auth()->user()['email']);
            Audit::log('app.update_rolled_back', null, null, ['to' => $restored['ref'] ?? null]);
        } catch (UpdateException $e) {
            $this->error($e->getMessage());
            return $this->back('/admin/updates/');
        }
        return $this->finishing($request, 'Restoring the previous version');
    }

    /** Tiny page that immediately posts to finish(), so the new code does the rest. */
    private function finishing(Request $request, string $what): Response
    {
        $nonce = $this->app->cspNonce();
        $action = e(link_to('/admin/updates/finish'));
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Updating…</title>'
            . '<link rel="stylesheet" href="' . e(asset('assets/css/panel.css')) . '"></head><body><div class="standalone"><div class="card">'
            . '<h1>' . e($what) . '…</h1><p class="muted">Applying database changes and refreshing caches. Please keep this page open.</p>'
            . '<form method="post" action="' . $action . '" id="finish">' . csrf_field() . '<button class="btn" type="submit">Continue</button></form>'
            . '</div></div><script nonce="' . e($nonce) . '">document.getElementById("finish").submit();</script></body></html>';
        return Response::html($html)->header('Cache-Control', 'no-store');
    }

    private function clearCaches(): void
    {
        PageCache::flush();
        foreach (glob($this->app->path('public/assets/build') . '/*.min.*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink($this->app->path('storage/cache/updates-check.json'));
    }

    /** Latest release + branch head from GitHub, cached briefly (GitHub allows 60 calls/hour without a token). */
    private function remote(bool $refresh): array
    {
        $cache = $this->app->path('storage/cache/updates-check.json');
        if (!$refresh && is_file($cache) && filemtime($cache) > time() - self::CHECK_TTL) {
            $data = json_decode((string) file_get_contents($cache), true);
            if (is_array($data)) {
                return $data;
            }
        }
        $source = GitHubSource::fromConfig();
        $data = ['release' => $source->latestRelease(), 'head' => $source->branchHead(), 'branch' => $source->branch(), 'checked_at' => gmdate('c')];
        @file_put_contents($cache, json_encode($data));
        return $data;
    }

    private function updater(): Updater
    {
        return new Updater($this->app->path());
    }
}
