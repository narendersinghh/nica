<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Vendor\VendorProducts;

final class ProductController extends VendorController
{
    public function index(Request $request): Response
    {
        $id = $this->vendorId($request);
        $products = new VendorProducts($this->app->db());
        $status = $request->query('status');
        $status = is_string($status) && isset(VendorProducts::STATUS_LABELS[$status]) ? $status : null;
        $search = trim((string) $request->query('s', ''));
        $list = $products->list($id, $status, mb_substr_safe($search, 100), $this->page($request));
        $this->app->images()->prime(array_filter(array_map('intval', array_column($list['rows'], 'featured_media_id'))));

        return $this->panel($request, 'vendor/products', [
            'title' => 'Products',
            'tab' => 'products',
            'list' => $list,
            'counts' => $products->counts($id),
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->editor($request, null);
    }

    public function edit(Request $request): Response
    {
        return $this->editor($request, $this->product($request));
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, $this->product($request));
    }

    public function duplicate(Request $request): Response
    {
        $copy = (new VendorProducts($this->app->db()))->duplicate($this->vendorId($request), (int) $request->param('id'));
        if ($copy === null) {
            throw new HttpException(404);
        }
        $this->notice('Product duplicated. The copy is saved as a draft.');
        return $this->back('/dashboard/products/' . $copy . '/');
    }

    public function archive(Request $request): Response
    {
        if ((new VendorProducts($this->app->db()))->archive($this->vendorId($request), (int) $request->param('id'))) {
            $this->notice('Product moved to the archive. It is no longer visible in the shop.');
        }
        return $this->back('/dashboard/products/');
    }

    public function restore(Request $request): Response
    {
        if ((new VendorProducts($this->app->db()))->restore($this->vendorId($request), (int) $request->param('id'))) {
            $this->notice('Product restored as a draft.');
        }
        return $this->back('/dashboard/products/' . (int) $request->param('id') . '/');
    }

    /** Inline stock edit from the products list (JSON). */
    public function stock(Request $request): Response
    {
        $qty = filter_var($request->post('qty'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99999]]);
        if ($qty === false) {
            return Response::json(['error' => 'Enter a whole number.'], 422);
        }
        $ok = (new VendorProducts($this->app->db()))->setStock($this->vendorId($request), (int) $request->post('variant'), $qty);
        return $ok ? Response::json(['ok' => true, 'qty' => $qty]) : Response::json(['error' => 'Not found.'], 404);
    }

    private function product(Request $request): array
    {
        $product = (new VendorProducts($this->app->db()))->find($this->vendorId($request), (int) $request->param('id'));
        if ($product === null) {
            throw new HttpException(404);
        }
        return $product;
    }

    private function editor(Request $request, ?array $product, array $errors = [], array $input = []): Response
    {
        $products = new VendorProducts($this->app->db());
        $data = $products->editorData($product);
        $this->app->images()->prime($data['images']);
        return $this->panel($request, 'vendor/product-edit', $data + [
            'title' => $product ? 'Edit product' : 'Add product',
            'tab' => 'products',
            'back' => ['Products', '/dashboard/products/'],
            'product' => $product,
            'input' => $input,
            'formErrors' => $errors,
        ]);
    }

    private function save(Request $request, ?array $product): Response
    {
        $uploads = self::files($request->file('new_images'));
        $result = (new VendorProducts($this->app->db()))->save($this->vendor($request), $product, $request->post(), $uploads, $this->app->auth()->id());
        if ($result['errors']) {
            // Re-render with what was typed (files have to be chosen again).
            $this->error('Please fix the highlighted fields.');
            $response = $this->editor($request, $product, $result['errors'], $request->post());
            return $response;
        }
        $saved = $this->app->db()->first('SELECT status, slug FROM products WHERE id = ?', [$result['id']]);
        $message = match ($saved['status']) {
            'published' => 'Product saved and live in the shop.',
            'pending' => 'Product submitted for review. We will publish it shortly.',
            default => 'Product saved as a draft.',
        };
        $this->notice($message . ($result['warnings'] ? ' ' . implode(' ', $result['warnings']) : ''));
        return $this->back('/dashboard/products/' . $result['id'] . '/');
    }

    /** Normalise a multi-file input into a list of single-file arrays. */
    public static function files(?array $field): array
    {
        if (!$field || !isset($field['name'])) {
            return [];
        }
        if (!is_array($field['name'])) {
            return ($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$field];
        }
        $files = [];
        foreach ($field['name'] as $i => $name) {
            if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = ['name' => $name, 'type' => $field['type'][$i] ?? '', 'tmp_name' => $field['tmp_name'][$i] ?? '', 'error' => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $field['size'][$i] ?? 0];
        }
        return $files;
    }
}
