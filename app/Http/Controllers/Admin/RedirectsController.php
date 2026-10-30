<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Services\Audit;

/** Redirects (imported from Rank Math, editable) and the log of pages people could not find. */
final class RedirectsController extends AdminController
{
    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $search = trim((string) $request->query('s', ''));
        $params = $search !== '' ? [self::like($search), self::like($search)] : [];
        return $this->panel($request, 'admin/redirects', [
            'title' => 'Redirects & 404s',
            'tab' => 'redirects',
            'search' => $search,
            'redirects' => $db->select('SELECT * FROM redirects' . ($search !== '' ? ' WHERE from_path LIKE ? OR to_url LIKE ?' : '') . ' ORDER BY created_at DESC LIMIT 300', $params),
            'misses' => $db->select('SELECT * FROM not_found_log ORDER BY hits DESC, last_seen_at DESC LIMIT 50'),
            'prefill' => (string) $request->query('from', ''),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, ['from_path' => 'required|string|max:190', 'to_url' => 'nullable|string|max:500', 'status_code' => 'required|in:301,302,410']);
        $from = '/' . trim((string) parse_url(trim($data['from_path']), PHP_URL_PATH), '/') . '/';
        $from = $from === '//' ? '/' : $from;
        $to = trim((string) ($data['to_url'] ?? ''));
        if ($data['status_code'] !== '410') {
            $isUrl = preg_match('#^https?://#i', $to) && filter_var($to, FILTER_VALIDATE_URL);
            if (!$isUrl && !preg_match('#^/[^\s]*$#', $to)) {
                throw new ValidationException(['to_url' => 'must be a path starting with / or a full https:// address.'], $request);
            }
            if (!$isUrl && '/' . trim((string) parse_url($to, PHP_URL_PATH), '/') . '/' === $from) {
                throw new ValidationException(['to_url' => 'points back to the same page.'], $request);
            }
        }
        if ($from === '/') {
            throw new ValidationException(['from_path' => 'cannot be the home page.'], $request);
        }
        $db = $this->app->db();
        $db->query('INSERT INTO redirects (from_path, to_url, status_code) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE to_url = VALUES(to_url), status_code = VALUES(status_code)', [$from, $to !== '' ? $to : '/', (int) $data['status_code']]);
        $db->delete('not_found_log', ['path' => $from]);
        Audit::log('redirect.saved', 'redirect', null, ['from' => $from, 'to' => $to, 'code' => (int) $data['status_code']]);
        $this->notice('Redirect saved for ' . $from);
        return $this->back('/admin/redirects/');
    }

    public function delete(Request $request): Response
    {
        $row = $this->app->db()->first('SELECT * FROM redirects WHERE id = ?', [(int) $request->param('id')]);
        if ($row) {
            $this->app->db()->delete('redirects', ['id' => (int) $row['id']]);
            Audit::log('redirect.deleted', 'redirect', (int) $row['id'], ['from' => $row['from_path'], 'to' => $row['to_url']]);
            $this->notice('Redirect removed.');
        }
        return $this->back('/admin/redirects/');
    }

    public function dismiss(Request $request): Response
    {
        $path = (string) $request->post('path', '');
        $path === '*' ? $this->app->db()->query('DELETE FROM not_found_log') : $this->app->db()->delete('not_found_log', ['path' => $path]);
        $this->notice($path === '*' ? '404 log cleared.' : 'Removed from the 404 log.');
        return $this->back('/admin/redirects/#not-found');
    }
}
