<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\Request;
use App\Core\Http\Response;

/** Reviews on the vendor's products; the vendor can post one public reply each. */
final class ReviewController extends VendorController
{
    public function index(Request $request): Response
    {
        $id = $this->vendorId($request);
        $db = $this->app->db();
        $filter = $request->query('filter') === 'unanswered' ? 'unanswered' : 'all';
        $page = $this->page($request);
        $where = "r.vendor_id = ? AND r.status = 'approved'" . ($filter === 'unanswered' ? ' AND r.vendor_reply IS NULL' : '');
        $total = (int) $db->scalar("SELECT COUNT(*) FROM reviews r WHERE {$where}", [$id]);
        $reviews = $db->select(
            "SELECT r.*, p.name AS product_name, p.slug AS product_slug FROM reviews r JOIN products p ON p.id = r.product_id
             WHERE {$where} ORDER BY r.created_at DESC LIMIT 20 OFFSET " . (($page - 1) * 20),
            [$id]
        );
        $stats = $db->first("SELECT COUNT(*) n, COALESCE(AVG(rating), 0) avg, SUM(vendor_reply IS NULL) unanswered FROM reviews WHERE vendor_id = ? AND status = 'approved'", [$id]);
        return $this->panel($request, 'vendor/reviews', [
            'title' => 'Reviews',
            'tab' => 'reviews',
            'reviews' => $reviews,
            'stats' => $stats,
            'filter' => $filter,
            'pages' => max(1, (int) ceil($total / 20)),
            'page' => $page,
        ]);
    }

    public function reply(Request $request): Response
    {
        $data = $this->validate($request, ['reply' => 'nullable|string|max:2000']);
        $reply = trim((string) ($data['reply'] ?? ''));
        $updated = $this->app->db()->query(
            "UPDATE reviews SET vendor_reply = ? WHERE id = ? AND vendor_id = ? AND status = 'approved'",
            [$reply !== '' ? $reply : null, (int) $request->param('id'), $this->vendorId($request)]
        )->rowCount();
        if ($updated) {
            $this->notice($reply !== '' ? 'Reply published.' : 'Reply removed.');
        }
        return $this->back('/dashboard/reviews/#review-' . (int) $request->param('id'));
    }
}
