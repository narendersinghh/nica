<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Parallel HTTP GET downloader (curl_multi). Only ever reads from the source
 * site; files that already exist locally are skipped, so re-runs are cheap.
 */
final class Downloader
{
    private const MAX_BYTES = 200 * 1024 * 1024;

    public function __construct(private int $concurrency = 6, private int $timeout = 120)
    {
        if (!function_exists('curl_multi_init')) {
            throw new \RuntimeException('The curl extension is required for downloading media.');
        }
    }

    /**
     * @param array<string,string> $jobs url => absolute local path
     * @param callable(string $url, bool $ok, string $error):void|null $progress
     * @return array{ok:int,skipped:int,failed:array<string,string>}
     */
    public function fetch(array $jobs, ?callable $progress = null): array
    {
        $result = ['ok' => 0, 'skipped' => 0, 'failed' => []];
        $queue = [];
        foreach ($jobs as $url => $path) {
            if (is_file($path) && filesize($path) > 0) {
                $result['skipped']++;
                continue;
            }
            $queue[] = [$url, $path];
        }

        $multi = curl_multi_init();
        $active = [];
        $start = function () use (&$queue, &$active, $multi): void {
            while (count($active) < $this->concurrency && $queue !== []) {
                [$url, $path] = array_shift($queue);
                $dir = dirname($path);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    continue;
                }
                $tmp = $path . '.part';
                $fh = fopen($tmp, 'wb');
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $fh,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_USERAGENT => 'NiCa-Migration/1.0',
                    CURLOPT_FAILONERROR => true,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => static fn ($c, $dlTotal, $dlNow) => $dlNow > self::MAX_BYTES ? 1 : 0,
                ]);
                curl_multi_add_handle($multi, $ch);
                $active[(int) $ch] = [$ch, $fh, $url, $path, $tmp];
            }
        };

        $start();
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
            while ($info = curl_multi_info_read($multi)) {
                $ch = $info['handle'];
                [, $fh, $url, $path, $tmp] = $active[(int) $ch];
                fclose($fh);
                $ok = $info['result'] === CURLE_OK;
                $error = $ok ? '' : curl_error($ch);
                if ($ok) {
                    rename($tmp, $path);
                    $result['ok']++;
                } else {
                    @unlink($tmp);
                    $result['failed'][$url] = $error;
                }
                if ($progress) {
                    $progress($url, $ok, $error);
                }
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                unset($active[(int) $ch]);
                $start();
            }
        } while (($running || $active !== []) && $status === CURLM_OK);

        curl_multi_close($multi);
        return $result;
    }
}
