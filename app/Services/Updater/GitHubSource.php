<?php

declare(strict_types=1);

namespace App\Services\Updater;

/**
 * Read-only access to the app's GitHub repository: latest release, branch
 * head, the commits between two versions, and the code zip of a version.
 * Downloads only ever come from GitHub hosts over TLS.
 */
final class GitHubSource
{
    private const API = 'https://api.github.com/';
    private const ALLOWED_HOSTS = ['api.github.com', 'codeload.github.com', 'github.com'];
    private const MAX_ZIP_BYTES = 60 * 1024 * 1024;

    public function __construct(private string $repository, private string $branch, private string $token = '')
    {
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
            throw new UpdateException('UPDATE_REPOSITORY must look like "owner/name".');
        }
        if (!preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch) || str_contains($branch, '..')) {
            throw new UpdateException('UPDATE_BRANCH is not a valid branch name.');
        }
    }

    public static function fromConfig(): self
    {
        return new self((string) config('updates.repository'), (string) config('updates.branch', 'main'), (string) config('updates.token', ''));
    }

    public function repository(): string
    {
        return $this->repository;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    /** @return array{tag:string,name:string,notes:string,published_at:string,url:string,sha:string}|null */
    public function latestRelease(): ?array
    {
        $release = $this->api('repos/' . $this->repository . '/releases/latest', true);
        if ($release === null) {
            return null;
        }
        $tag = (string) $release['tag_name'];
        return [
            'tag' => $tag,
            'name' => (string) ($release['name'] ?: $tag),
            'notes' => (string) ($release['body'] ?? ''),
            'published_at' => (string) ($release['published_at'] ?? ''),
            'url' => (string) ($release['html_url'] ?? ''),
            'sha' => $this->commit($tag)['sha'],
        ];
    }

    /** @return array{sha:string,message:string,date:string,url:string} */
    public function branchHead(): array
    {
        return $this->commit($this->branch);
    }

    /** @return array{sha:string,message:string,date:string,url:string} */
    public function commit(string $ref): array
    {
        $c = $this->api('repos/' . $this->repository . '/commits/' . rawurlencode($ref));
        return [
            'sha' => (string) $c['sha'],
            'message' => trim((string) ($c['commit']['message'] ?? '')),
            'date' => (string) ($c['commit']['committer']['date'] ?? $c['commit']['author']['date'] ?? ''),
            'url' => (string) ($c['html_url'] ?? ''),
        ];
    }

    /**
     * Commits from $base (exclusive) to $head, newest first.
     * @return array{ahead:int, behind:int, commits: array<int, array{sha:string,message:string,date:string}>}|null null when $base is unknown to GitHub
     */
    public function compare(string $base, string $head): ?array
    {
        $c = $this->api('repos/' . $this->repository . '/compare/' . rawurlencode($base) . '...' . rawurlencode($head), true);
        if ($c === null) {
            return null;
        }
        $commits = array_map(static fn ($x) => [
            'sha' => (string) $x['sha'],
            'message' => trim((string) ($x['commit']['message'] ?? '')),
            'date' => (string) ($x['commit']['committer']['date'] ?? ''),
        ], (array) ($c['commits'] ?? []));
        return ['ahead' => (int) ($c['ahead_by'] ?? 0), 'behind' => (int) ($c['behind_by'] ?? 0), 'commits' => array_reverse($commits)];
    }

    /** Download the code of a commit as a zip into $target. */
    public function download(string $sha, string $target): void
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new UpdateException('Refusing to download: not a full commit id.');
        }
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            throw new UpdateException('Cannot write ' . $target);
        }
        $url = self::API . 'repos/' . $this->repository . '/zipball/' . $sha;
        $bytes = 0;
        for ($hop = 0; $hop < 4; $hop++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $this->curlOptions() + [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static fn ($c, $total, $done) => $done > self::MAX_ZIP_BYTES ? 1 : 0,
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_error($ch);
            curl_close($ch);
            if ($ok === false) {
                fclose($fh);
                throw new UpdateException('Download failed: ' . $error);
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                // GitHub redirects to codeload.github.com; never follow anywhere else.
                if (!in_array(parse_url($location, PHP_URL_HOST), self::ALLOWED_HOSTS, true) || parse_url($location, PHP_URL_SCHEME) !== 'https') {
                    fclose($fh);
                    throw new UpdateException('Download redirected to an unexpected host.');
                }
                ftruncate($fh, 0);
                rewind($fh);
                $url = $location;
                continue;
            }
            fclose($fh);
            if ($status !== 200) {
                throw new UpdateException('GitHub returned HTTP ' . $status . ' for the download' . ($status === 404 ? ' (private repository? set UPDATE_GITHUB_TOKEN)' : '') . '.');
            }
            $bytes = (int) filesize($target);
            if ($bytes < 1000 || $bytes > self::MAX_ZIP_BYTES) {
                throw new UpdateException('Unexpected download size (' . $bytes . ' bytes).');
            }
            return;
        }
        fclose($fh);
        throw new UpdateException('Too many redirects while downloading.');
    }

    private function api(string $path, bool $allow404 = false): ?array
    {
        if (!function_exists('curl_init')) {
            throw new UpdateException('The curl extension is required for updates.');
        }
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, $this->curlOptions() + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new UpdateException('Could not reach GitHub: ' . $error);
        }
        if ($status === 404 && $allow404) {
            return null;
        }
        $data = json_decode((string) $raw, true);
        if ($status === 403 && str_contains((string) $raw, 'rate limit')) {
            throw new UpdateException('GitHub rate limit reached. Try again in an hour, or set UPDATE_GITHUB_TOKEN.');
        }
        if ($status !== 200 || !is_array($data)) {
            throw new UpdateException('GitHub returned HTTP ' . $status . ($status === 404 ? ' (check UPDATE_REPOSITORY, or set UPDATE_GITHUB_TOKEN for a private repository)' : '') . '.');
        }
        return $data;
    }

    private function curlOptions(): array
    {
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        return [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'nica-updater',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];
    }
}
