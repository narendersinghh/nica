<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controllers\Vendor\ProductController as VendorProductController;
use App\Services\Audit;
use App\Services\Mail\Mailer;
use App\Services\Vendor\VendorProducts;

final class ProductsController extends AdminController
{
    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $status = $request->query('status');
        $status = is_string($status) && isset(VendorProducts::STATUS_LABELS[$status]) ? $status : null;
        $vendorId = (int) $request->query('vendor', 0);
        $search = trim((string) $request->query('s', ''));
        $lowStock = $request->query('stock') === 'low';
        $page = $this->page($request);

        $where = $status ? 'p.status = ?' : "p.status <> 'archived'";
        $params = $status ? [$status] : [];
        if ($vendorId) {
            $where .= ' AND p.vendor_id = ?';
            $params[] = $vendorId;
        }
        if ($search !== '') {
            $where .= ' AND (p.name LIKE ? OR EXISTS (SELECT 1 FROM product_variants s WHERE s.product_id = p.id AND s.sku = ?))';
            array_push($params, self::like($search), $search);
        }
        if ($lowStock) {
            $where .= ' AND EXISTS (SELECT 1 FROM product_variants s WHERE s.product_id = p.id AND s.is_active = 1 AND s.manage_stock = 1 AND s.stock_qty <= 3)';
        }
        $total = (int) $db->scalar("SELECT COUNT(*) FROM products p WHERE {$where}", $params);
        $rows = $db->select(
            "SELECT p.id, p.slug, p.name, p.status, p.featured_media_id, p.price_min_cents, p.price_max_cents, p.in_stock, p.is_featured, p.nica_score_verified,
                    p.sales_count, p.review_note, p.updated_at, v.store_name, v.id AS vendor_id
             FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE {$where}
             ORDER BY FIELD(p.status, 'pending') DESC, p.updated_at DESC LIMIT 25 OFFSET " . (($page - 1) * 25),
            $params
        );
        $this->app->images()->prime(array_filter(array_map('intval', array_column($rows, 'featured_media_id'))));
        $counts = array_fill_keys(array_keys(VendorProducts::STATUS_LABELS), 0);
        foreach ($db->select('SELECT status, COUNT(*) n FROM products GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $this->panel($request, 'admin/products', [
            'title' => 'Products', 'tab' => 'products', 'rows' => $rows, 'counts' => $counts, 'status' => $status, 'search' => $search,
            'vendorId' => $vendorId, 'lowStock' => $lowStock, 'page' => $page, 'pages' => max(1, (int) ceil($total / 25)),
            'vendors' => $db->select('SELECT id, store_name FROM vendors ORDER BY store_name'),
        ]);
    }

    public function edit(Request $request): Response
    {
        return $this->editor($request, $this->product($request));
    }

    public function update(Request $request): Response
    {
        $product = $this->product($request);
        $vendor = $this->app->db()->first('SELECT * FROM vendors WHERE id = ?', [$product['vendor_id']]);
        $result = (new VendorProducts($this->app->db()))->save($vendor, $product, $request->post(), VendorProductController::files($request->file('new_images')), $this->app->auth()->id(), true);
        if ($result['errors']) {
            $this->error('Please fix the highlighted fields.');
            return $this->editor($request, $product, $result['errors'], $request->post());
        }
        Audit::log('product.edited', 'product', (int) $product['id']);
        $this->notice('Product saved.' . ($result['warnings'] ? ' ' . implode(' ', $result['warnings']) : ''));
        return $this->back('/admin/products/' . $product['id'] . '/');
    }

    /** approve | reject | feature | unfeature | verify | unverify | archive */
    public function action(Request $request): Response
    {
        $product = $this->product($request);
        $db = $this->app->db();
        $action = (string) $request->param('action');
        $note = trim((string) $request->post('note', ''));
        $update = match ($action) {
            'approve' => ['status' => 'published', 'review_note' => null, 'published_at' => $product['published_at'] ?? gmdate('Y-m-d H:i:s')],
            'reject' => ['status' => 'rejected', 'review_note' => mb_substr_safe($note !== '' ? $note : 'Please review the product details and images.', 500)],
            'feature' => ['is_featured' => 1],
            'unfeature' => ['is_featured' => 0],
            'verify' => ['nica_score_verified' => 1],
            'unverify' => ['nica_score_verified' => 0],
            'archive' => ['status' => 'archived'],
            default => throw new HttpException(404),
        };
        $db->update('products', $update, ['id' => (int) $product['id']]);
        Audit::log('product.' . $action, 'product', (int) $product['id'], $note !== '' ? ['note' => $note] : []);

        if (in_array($action, ['approve', 'reject'], true)) {
            $email = $db->scalar('SELECT u.email FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.id = ?', [$product['vendor_id']]);
            if ($email) {
                (new Mailer())->queue((string) $email, '[' . config('app.name') . '] ' . ($action === 'approve' ? 'Your product is live: ' : 'Changes needed: ') . $product['name'],
                    $this->app->view()->render('emails/product-review', ['product' => $product, 'approved' => $action === 'approve', 'note' => $update['review_note'] ?? '']));
            }
        }
        $this->notice('"' . $product['name'] . '" updated.');
        $back = (string) $request->post('return', '');
        return $this->back(str_starts_with($back, '/admin/') ? $back : '/admin/products/');
    }

    private function editor(Request $request, array $product, array $errors = [], array $input = []): Response
    {
        $data = (new VendorProducts($this->app->db()))->editorData($product);
        $this->app->images()->prime($data['images']);
        return $this->panel($request, 'vendor/product-edit', $data + [
            'title' => 'Edit product',
            'tab' => 'products',
            'back' => ['Products', '/admin/products/'],
            'product' => $product,
            'vendor' => $this->app->db()->first('SELECT * FROM vendors WHERE id = ?', [$product['vendor_id']]),
            'formAction' => '/admin/products/' . $product['id'] . '/',
            'input' => $input,
            'formErrors' => $errors,
        ]);
    }

    private function product(Request $request): array
    {
        return $this->app->db()->first('SELECT * FROM products WHERE id = ?', [(int) $request->param('id')]) ?? throw new HttpException(404);
    }
}
