<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http\Request;
use App\Core\Http\Response;

/** Admin-managed redirects (imported from Rank Math) and a log of 404s to fix. */
final class Redirects
{
    public static function resolve(Request $request): ?Response
    {
        if (!$request->isMethod('GET', 'HEAD')) {
            return null;
        }
        $path = '/' . trim($request->path(), '/') . '/';
        try {
            $db = app()->db();
            $row = $db->first('SELECT id, to_url, status_code FROM redirects WHERE from_path IN (?, ?)', [$path, rtrim($path, '/')]);
            if ($row === null) {
                self::logMiss($request, $path);
                return null;
            }
            $db->query('UPDATE redirects SET hits = hits + 1, last_hit_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        } catch (\PDOException) {
            return null; // database down: just show the 404
        }
        if ((int) $row['status_code'] === 410) {
            return null;
        }
        return Response::redirect((string) $row['to_url'], (int) $row['status_code']);
    }

    private static function logMiss(Request $request, string $path): void
    {
        // Skip asset probes and obvious scanner noise to keep the log useful.
        if (preg_match('#\.(php|env|git|sql|bak|ini|xml\.gz)|/wp-(includes|json)/#i', $path) || strlen($path) > 191) {
            return;
        }
        $referrer = substr((string) $request->header('Referer', ''), 0, 500) ?: null;
        app()->db()->query(
            'INSERT INTO not_found_log (path, hits, referrer, last_seen_at) VALUES (?, 1, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE hits = hits + 1, referrer = COALESCE(VALUES(referrer), referrer), last_seen_at = UTC_TIMESTAMP()',
            [$path, $referrer],
        );
    }
}
