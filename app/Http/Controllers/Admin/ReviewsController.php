<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Audit;
use App\Services\Catalog\ProductIndexer;

final class ReviewsController extends AdminController
{
    public const STATUSES = ['pending' => 'To moderate', 'approved' => 'Published', 'spam' => 'Spam', 'rejected' => 'Rejected'];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $status = (string) $request->query('status', 'pending');
        $status = isset(self::STATUSES[$status]) ? $status : 'pending';
        $page = $this->page($request);
        $total = (int) $db->scalar('SELECT COUNT(*) FROM reviews WHERE status = ?', [$status]);
        $rows = $db->select(
            'SELECT r.*, p.name AS product_name, p.slug AS product_slug, v.store_name FROM reviews r JOIN products p ON p.id = r.product_id JOIN vendors v ON v.id = r.vendor_id
             WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 20 OFFSET ' . (($page - 1) * 20),
            [$status]
        );
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($db->select('SELECT status, COUNT(*) n FROM reviews GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $this->panel($request, 'admin/reviews', ['title' => 'Reviews', 'tab' => 'reviews', 'rows' => $rows, 'status' => $status, 'counts' => $counts, 'page' => $page, 'pages' => max(1, (int) ceil($total / 20))]);
    }

    /** approve | spam | reject | feature | unfeature | delete */
    public function action(Request $request): Response
    {
        $db = $this->app->db();
        $review = $db->first('SELECT * FROM reviews WHERE id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
        $action = (string) $request->param('action');
        match ($action) {
            'approve' => $db->update('reviews', ['status' => 'approved'], ['id' => (int) $review['id']]),
            'spam' => $db->update('reviews', ['status' => 'spam'], ['id' => (int) $review['id']]),
            'reject' => $db->update('reviews', ['status' => 'rejected'], ['id' => (int) $review['id']]),
            'feature' => $db->update('reviews', ['is_featured' => 1], ['id' => (int) $review['id']]),
            'unfeature' => $db->update('reviews', ['is_featured' => 0], ['id' => (int) $review['id']]),
            'delete' => $db->delete('reviews', ['id' => (int) $review['id']]),
            default => throw new HttpException(404),
        };
        (new ProductIndexer($db))->refreshRatings((int) $review['product_id']);
        Audit::log('review.' . $action, 'review', (int) $review['id']);
        $this->notice('Review updated.');
        return $this->back('/admin/reviews/?status=' . ($action === 'delete' ? $review['status'] : (string) $request->post('return_status', $review['status'])));
    }
}
