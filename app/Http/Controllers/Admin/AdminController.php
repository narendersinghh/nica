<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PanelHelpers;
use App\Http\Middleware\AdminAccess;
use App\Core\Http\HttpException;

/** Base for /admin/ pages (the AdminAccess middleware has checked the role). */
abstract class AdminController extends Controller
{
    use PanelHelpers;

    protected function panel(Request $request, string $view, array $data = []): Response
    {
        $db = $this->app->db();
        $badges = [
            'orders' => (int) $db->scalar("SELECT COUNT(*) FROM orders WHERE status = 'on-hold'"),
            'vendors' => (int) $db->scalar("SELECT COUNT(*) FROM vendors WHERE status = 'pending'"),
            'products' => (int) $db->scalar("SELECT COUNT(*) FROM products WHERE status = 'pending'"),
            'payouts' => (int) $db->scalar("SELECT (SELECT COUNT(*) FROM payout_requests WHERE status IN ('pending','approved')) + (SELECT COUNT(*) FROM stylist_payout_requests WHERE status IN ('pending','approved'))"),
            'stylists' => (int) $db->scalar("SELECT COUNT(*) FROM stylists WHERE status = 'pending'"),
            'reviews' => (int) $db->scalar("SELECT COUNT(*) FROM reviews WHERE status = 'pending'"),
        ];
        $response = $this->view($view, $data + [
            'area' => 'admin',
            'badges' => $badges,
            'errors' => $this->app->session()->getFlash('_errors', []),
        ]);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    protected function authorize(string $ability): void
    {
        if (!AdminAccess::can($ability)) {
            throw new HttpException(403);
        }
    }

    /** CSV download (formula-injection safe: cells starting with = + - @ are prefixed). */
    protected function csv(string $filename, array $header, iterable $rows): Response
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map(static function ($v) {
                $v = (string) $v;
                return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v) ? "'" . $v : $v;
            }, $row));
        }
        rewind($out);
        $body = (string) stream_get_contents($out);
        fclose($out);
        return (new Response($body, 200))
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"')
            ->header('Cache-Control', 'no-store, private');
    }
}
