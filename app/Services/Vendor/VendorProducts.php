<?php

declare(strict_types=1);

namespace App\Services\Vendor;

use App\Core\Database;
use App\Services\Catalog\ProductIndexer;
use App\Services\Content\HtmlSanitizer;
use App\Services\Media\ImageUploader;
use App\Services\Settings;

/**
 * Product management for one vendor: listing, the editor's data, saving
 * (simple products or a variation matrix built from Length/Color values),
 * duplicating and archiving. Every query is scoped to the vendor.
 */
final class VendorProducts
{
    public const STATUS_LABELS = ['published' => 'Online', 'draft' => 'Draft', 'pending' => 'In review', 'rejected' => 'Needs changes', 'archived' => 'Archived'];

    public function __construct(private Database $db)
    {
    }

    public function counts(int $vendorId): array
    {
        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($this->db->select('SELECT status, COUNT(*) n FROM products WHERE vendor_id = ? GROUP BY status', [$vendorId]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    public function list(int $vendorId, ?string $status, string $search, int $page, int $perPage = 20): array
    {
        $where = 'p.vendor_id = ?';
        $params = [$vendorId];
        if ($status !== null && isset(self::STATUS_LABELS[$status])) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        } else {
            $where .= " AND p.status <> 'archived'";
        }
        if ($search !== '') {
            $where .= ' AND (p.name LIKE ? OR EXISTS (SELECT 1 FROM product_variants s WHERE s.product_id = p.id AND s.sku = ?))';
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
            $params[] = $search;
        }
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM products p WHERE {$where}", $params);
        $rows = $this->db->select(
            "SELECT p.id, p.slug, p.name, p.type, p.status, p.featured_media_id, p.price_min_cents, p.price_max_cents, p.regular_min_cents, p.on_sale,
                    p.in_stock, p.sales_count, p.rating_avg, p.rating_count, p.review_note, p.updated_at,
                    (SELECT SUM(stock_qty) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1 AND v.manage_stock = 1) AS stock_total,
                    (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variant_count,
                    c.name AS category
             FROM products p LEFT JOIN product_categories c ON c.id = p.primary_category_id
             WHERE {$where} ORDER BY p.updated_at DESC, p.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage)), 'page' => $page];
    }

    public function find(int $vendorId, int $productId): ?array
    {
        return $this->db->first('SELECT * FROM products WHERE id = ? AND vendor_id = ?', [$productId, $vendorId]);
    }

    /** Everything the editor needs. $product is null for a new product. */
    public function editorData(?array $product): array
    {
        $id = $product ? (int) $product['id'] : 0;
        $attributes = $this->db->select('SELECT * FROM attributes ORDER BY sort_order, id');
        foreach ($attributes as &$a) {
            $a['values'] = $this->db->select('SELECT id, name, swatch FROM attribute_values WHERE attribute_id = ? ORDER BY sort_order, name', [$a['id']]);
        }
        unset($a);

        $variants = [];
        if ($id) {
            foreach ($this->db->select('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id', [$id]) as $v) {
                $v['values'] = [];
                $variants[(int) $v['id']] = $v;
            }
            if ($variants) {
                foreach ($this->db->select('SELECT variant_id, attribute_id, attribute_value_id FROM variant_values WHERE variant_id IN (' . implode(',', array_keys($variants)) . ')') as $vv) {
                    $variants[(int) $vv['variant_id']]['values'][(int) $vv['attribute_id']] = (int) $vv['attribute_value_id'];
                }
            }
        }
        $byKey = [];
        foreach ($variants as $v) {
            $byKey[self::comboKey($v['values'])] = $v;
        }

        return [
            'attributes' => $attributes,
            'variationAttributes' => $id
                ? array_map('intval', array_column($this->db->select('SELECT DISTINCT av.attribute_id FROM product_attribute_values pav JOIN attribute_values av ON av.id = pav.attribute_value_id WHERE pav.product_id = ? AND pav.used_for_variants = 1', [$id]), 'attribute_id'))
                : array_map('intval', array_column($attributes, 'id')),
            'selectedValues' => $id ? array_map('intval', array_column($this->db->select('SELECT attribute_value_id FROM product_attribute_values WHERE product_id = ?', [$id]), 'attribute_value_id')) : [],
            'variants' => $byKey,
            'categories' => $this->db->select('SELECT id, parent_id, name, path FROM product_categories ORDER BY path'),
            'selectedCategories' => $id ? array_map('intval', array_column($this->db->select('SELECT category_id FROM product_category WHERE product_id = ?', [$id]), 'category_id')) : [],
            'tags' => $this->db->select('SELECT id, name FROM product_tags ORDER BY sort_order, name'),
            'selectedTags' => $id ? array_map('intval', array_column($this->db->select('SELECT tag_id FROM product_tag WHERE product_id = ?', [$id]), 'tag_id')) : [],
            'images' => $id ? array_map('intval', array_column($this->db->select('SELECT media_id FROM product_images WHERE product_id = ? ORDER BY sort_order', [$id]), 'media_id')) : [],
            'shippingClasses' => self::shippingClasses(),
        ];
    }

    /** '' = standard flat rate, plus every configured shipping class. */
    public static function shippingClasses(): array
    {
        $classes = ['' => 'Standard (' . money((int) Settings::get('commerce.shipping.flat_rate.no_class_cents')) . ')'];
        foreach ((array) Settings::get('commerce.shipping.flat_rate.class_cents', []) as $slug => $cents) {
            $classes[$slug] = ucwords(str_replace('-', ' ', (string) $slug)) . ' (' . money((int) $cents) . ')';
        }
        return $classes;
    }

    /**
     * Combinations of the chosen attribute values, in attribute order.
     * @param array<int, int[]> $valuesByAttribute attribute id => value ids
     * @return array<string, array<int,int>> combo key => [attribute id => value id]
     */
    public static function combinations(array $valuesByAttribute): array
    {
        $combos = [[]];
        foreach ($valuesByAttribute as $attributeId => $valueIds) {
            if (!$valueIds) {
                continue;
            }
            $next = [];
            foreach ($combos as $combo) {
                foreach ($valueIds as $valueId) {
                    $next[] = $combo + [(int) $attributeId => (int) $valueId];
                }
            }
            $combos = $next;
        }
        $out = [];
        foreach ($combos as $combo) {
            if ($combo) {
                $out[self::comboKey($combo)] = $combo;
            }
        }
        return $out;
    }

    public static function comboKey(array $values): string
    {
        if (!$values) {
            return 'simple';
        }
        ksort($values);
        $parts = [];
        foreach ($values as $a => $v) {
            $parts[] = $a . '-' . $v;
        }
        return implode('_', $parts);
    }

    /** "$12.50" / "12.5" / "" -> cents or null. */
    public static function parseMoney(mixed $value): ?int
    {
        $value = trim(str_replace([',', '$', ' '], '', (string) $value));
        if ($value === '' || !preg_match('/^\d{1,7}(\.\d{1,2})?$/', $value)) {
            return null;
        }
        return (int) round((float) $value * 100);
    }

    /**
     * Create or update a product from the editor form.
     * @param array $input   POST data
     * @param array $uploads normalised list of $_FILES entries for new images
     * @return array{id:int, errors:array<string,string>, warnings:string[]}
     */
    public function save(array $vendor, ?array $product, array $input, array $uploads, ?int $userId, bool $skipReview = false): array
    {
        $errors = [];
        $warnings = [];
        $vendorId = (int) $vendor['id'];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || str_length($name) > 200) {
            $errors['name'] = 'Please enter a product name (up to 200 characters).';
        }
        $type = ($input['type'] ?? 'simple') === 'variable' ? 'variable' : 'simple';
        $wantPublish = ($input['intent'] ?? 'draft') === 'publish';
        $shippingClass = (string) ($input['shipping_class'] ?? '');
        if (!array_key_exists($shippingClass, self::shippingClasses())) {
            $shippingClass = '';
        }

        // Taxonomies: only ids that exist.
        $categoryIds = $this->existingIds('product_categories', (array) ($input['categories'] ?? []));
        $tagIds = $this->existingIds('product_tags', (array) ($input['tags'] ?? []));
        if ($wantPublish && !$categoryIds) {
            $errors['categories'] = 'Choose at least one category.';
        }

        // Variation values, grouped by attribute (only real values).
        // Attributes ticked "use for variations" build the matrix; the others are product details (e.g. Color: Black).
        $variationAttributes = array_map('intval', (array) ($input['variation_attributes'] ?? []));
        $valuesByAttribute = [];
        $valueAttribute = [];
        $selectedValues = array_values(array_unique(array_filter(array_map('intval', (array) ($input['attribute_values'] ?? [])))));
        if ($selectedValues) {
            foreach ($this->db->select('SELECT av.id, av.attribute_id FROM attribute_values av JOIN attributes a ON a.id = av.attribute_id WHERE av.id IN (' . implode(',', $selectedValues) . ') ORDER BY a.sort_order, a.id, av.sort_order, av.name') as $r) {
                $valueAttribute[(int) $r['id']] = (int) $r['attribute_id'];
                if (in_array((int) $r['attribute_id'], $variationAttributes, true)) {
                    $valuesByAttribute[(int) $r['attribute_id']][] = (int) $r['id'];
                }
            }
        }
        $combos = $type === 'variable' ? self::combinations($valuesByAttribute) : ['simple' => []];
        if ($type === 'variable' && !$combos) {
            $errors['attribute_values'] = 'Pick at least one value in an attribute used for variations.';
        }
        if (count($combos) > (int) config('catalog.max_variations', 100)) {
            $errors['attribute_values'] = 'That makes ' . count($combos) . ' variations. Please keep it under ' . (int) config('catalog.max_variations', 100) . '.';
        }

        // Variation rows (price, sale, SKU, stock).
        $rows = [];
        $posted = (array) ($input['variants'] ?? []);
        $bulkPrice = self::parseMoney($input['bulk_price'] ?? '');
        foreach ($combos as $key => $values) {
            $row = (array) ($posted[$key] ?? []);
            $regular = self::parseMoney($row['price'] ?? '') ?? $bulkPrice;
            $sale = self::parseMoney($row['sale_price'] ?? '');
            if ($sale !== null && $regular !== null && $sale >= $regular) {
                $errors['variants'] = 'A sale price must be lower than the regular price.';
            }
            // A quantity turns stock tracking on; leave it empty for made-to-order items.
            $manage = trim((string) ($row['stock_qty'] ?? '')) !== '';
            $qty = $manage ? max(0, min(99999, (int) $row['stock_qty'])) : null;
            $stockStatus = in_array($row['stock_status'] ?? 'instock', ['instock', 'outofstock', 'onbackorder'], true) ? $row['stock_status'] ?? 'instock' : 'instock';
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku !== '' && !preg_match('/^[A-Za-z0-9._\/-]{1,64}$/', $sku)) {
                $errors['variants'] = 'SKUs may only contain letters, numbers, dots, dashes and slashes.';
            }
            $rows[$key] = [
                'values' => $values,
                'regular_price_cents' => $regular,
                'sale_price_cents' => $sale,
                'sku' => $sku !== '' ? $sku : null,
                'manage_stock' => $manage ? 1 : 0,
                'stock_qty' => $qty,
                'stock_status' => $stockStatus,
                'weight_grams' => ($w = (int) ($row['weight'] ?? 0)) > 0 ? min($w, 100000) : null,
                'enabled' => $type === 'simple' || !empty($row['enabled']) || !isset($posted[$key]),
            ];
        }
        $priced = array_filter($rows, static fn ($r) => $r['regular_price_cents'] !== null && $r['regular_price_cents'] > 0 && $r['enabled']);
        if ($wantPublish && !$priced) {
            $errors[$type === 'simple' ? 'price' : 'variants'] = 'Add a price before publishing.';
        }
        if ($type === 'variable' && count($priced) < count(array_filter($rows, static fn ($r) => $r['enabled']))) {
            $warnings[] = 'Variations without a price stay hidden until you add one.';
        }

        // Duplicate SKUs within the store.
        $skus = array_filter(array_column($rows, 'sku'));
        if (count($skus) !== count(array_unique($skus))) {
            $errors['variants'] = 'Each SKU must be unique.';
        } elseif ($skus) {
            $taken = $this->db->scalar(
                'SELECT v.sku FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.vendor_id = ? AND v.is_active = 1 AND v.sku IN (' . implode(',', array_fill(0, count($skus), '?')) . ') AND p.id <> ? LIMIT 1',
                array_merge([$vendorId], array_values($skus), [$product ? (int) $product['id'] : 0])
            );
            if ($taken) {
                $errors['variants'] = 'SKU "' . $taken . '" is already used by another of your products.';
            }
        }

        // Images: existing ones the vendor kept (in order), then new uploads.
        $kept = array_values(array_unique(array_filter(array_map('intval', (array) ($input['images'] ?? [])))));
        if ($kept) {
            $allowed = array_map('intval', array_column($this->db->select(
                'SELECT id FROM media WHERE id IN (' . implode(',', $kept) . ') AND (uploaded_by = ? OR id IN (SELECT media_id FROM product_images WHERE product_id = ?) OR id = ?)',
                [$userId ?? 0, $product ? (int) $product['id'] : 0, $product ? (int) ($product['featured_media_id'] ?? 0) : 0]
            ), 'id'));
            $kept = array_values(array_intersect($kept, $allowed));
        }
        $max = (int) config('catalog.max_images', 12);
        if (count($kept) + count($uploads) > $max) {
            $errors['images'] = 'Up to ' . $max . ' images per product.';
        }

        if ($errors) {
            return ['id' => $product ? (int) $product['id'] : 0, 'errors' => $errors, 'warnings' => []];
        }

        $uploader = new ImageUploader($this->db, app()->path('public/uploads'));
        foreach ($uploads as $file) {
            try {
                $kept[] = $uploader->store($file, $userId, $name);
            } catch (\InvalidArgumentException $e) {
                $warnings[] = '"' . basename((string) ($file['name'] ?? 'image')) . '": ' . $e->getMessage();
            }
        }

        $review = !$skipReview && (bool) Settings::get('catalog.product_review', false);
        $status = $wantPublish ? ($review ? 'pending' : 'published') : 'draft';
        $sanitizer = new HtmlSanitizer();
        $data = [
            'name' => $name,
            'type' => $type,
            'status' => $status,
            'short_description' => ($s = trim($sanitizer->clean((string) ($input['short_description'] ?? '')))) !== '' ? $s : null,
            'description' => ($d = trim($sanitizer->clean((string) ($input['description'] ?? '')))) !== '' ? $d : null,
            'featured_media_id' => $kept[0] ?? null,
            'primary_category_id' => $categoryIds[0] ?? null,
            'shipping_class' => $shippingClass !== '' ? $shippingClass : null,
            'seo_title' => ($t = trim((string) ($input['seo_title'] ?? ''))) !== '' ? mb_substr_safe($t, 255) : null,
            'seo_description' => ($t = trim((string) ($input['seo_description'] ?? ''))) !== '' ? mb_substr_safe($t, 500) : null,
        ];
        if ($status === 'published' && ($product === null || $product['published_at'] === null)) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($product !== null && $product['status'] === 'rejected' && $status === 'draft') {
            $data['status'] = 'rejected'; // keep the admin's feedback visible until resubmitted
        }

        $id = $this->db->transaction(function () use ($product, $vendorId, $data, $name, $rows, $selectedValues, $valueAttribute, $variationAttributes, $type, $categoryIds, $tagIds, $kept) {
            if ($product === null) {
                $id = $this->db->insert('products', $data + ['vendor_id' => $vendorId, 'slug' => $this->uniqueSlug($name)]);
            } else {
                $id = (int) $product['id'];
                $this->db->update('products', $data + ($product['status'] === 'rejected' && $data['status'] !== 'rejected' ? ['review_note' => null] : []), ['id' => $id, 'vendor_id' => $vendorId]);
            }
            $this->syncVariants($id, $rows);
            $this->db->delete('product_attribute_values', ['product_id' => $id]);
            foreach ($selectedValues as $valueId) {
                if (isset($valueAttribute[$valueId])) {
                    $forVariants = $type === 'variable' && in_array($valueAttribute[$valueId], $variationAttributes, true);
                    $this->db->insert('product_attribute_values', ['product_id' => $id, 'attribute_value_id' => $valueId, 'used_for_variants' => $forVariants ? 1 : 0]);
                }
            }
            $this->syncPivot('product_category', 'category_id', $id, $categoryIds);
            $this->syncPivot('product_tag', 'tag_id', $id, $tagIds);
            $this->db->delete('product_images', ['product_id' => $id]);
            foreach (array_slice($kept, 1) as $i => $mediaId) {
                $this->db->insert('product_images', ['product_id' => $id, 'media_id' => $mediaId, 'sort_order' => $i]);
            }
            if (count($kept) > 0) {
                $this->db->update('products', ['featured_media_id' => $kept[0]], ['id' => $id]);
            }
            (new ProductIndexer($this->db))->refresh($id);
            return $id;
        });

        return ['id' => $id, 'errors' => [], 'warnings' => $warnings];
    }

    /**
     * Match posted rows to existing variants by their attribute values. Variants
     * that are no longer offered are switched off, never deleted (orders point at them).
     */
    private function syncVariants(int $productId, array $rows): void
    {
        $existing = [];
        $variantRows = $this->db->select('SELECT id FROM product_variants WHERE product_id = ?', [$productId]);
        $valueMap = [];
        if ($variantRows) {
            foreach ($this->db->select('SELECT variant_id, attribute_id, attribute_value_id FROM variant_values WHERE variant_id IN (' . implode(',', array_map('intval', array_column($variantRows, 'id'))) . ')') as $vv) {
                $valueMap[(int) $vv['variant_id']][(int) $vv['attribute_id']] = (int) $vv['attribute_value_id'];
            }
            foreach ($variantRows as $v) {
                $existing[self::comboKey($valueMap[(int) $v['id']] ?? [])] = (int) $v['id'];
            }
        }

        $sort = 0;
        $keep = [];
        foreach ($rows as $key => $row) {
            $fields = [
                'sku' => $row['sku'],
                'regular_price_cents' => (int) ($row['regular_price_cents'] ?? 0),
                'sale_price_cents' => $row['sale_price_cents'],
                'manage_stock' => $row['manage_stock'],
                'stock_qty' => $row['stock_qty'],
                'stock_status' => $row['stock_status'],
                'weight_grams' => $row['weight_grams'],
                'is_active' => $row['enabled'] && ($row['regular_price_cents'] ?? 0) > 0 ? 1 : 0,
                'sort_order' => $sort++,
            ];
            if (isset($existing[$key])) {
                $this->db->update('product_variants', $fields, ['id' => $existing[$key]]);
                $keep[] = $existing[$key];
            } else {
                $variantId = $this->db->insert('product_variants', $fields + ['product_id' => $productId]);
                foreach ($row['values'] as $attributeId => $valueId) {
                    $this->db->insert('variant_values', ['variant_id' => $variantId, 'attribute_id' => $attributeId, 'attribute_value_id' => $valueId]);
                }
                $keep[] = $variantId;
            }
        }
        $params = array_merge([$productId], $keep);
        $this->db->query('UPDATE product_variants SET is_active = 0 WHERE product_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : ''), $params);
    }

    public function duplicate(int $vendorId, int $productId): ?int
    {
        $product = $this->find($vendorId, $productId);
        if ($product === null) {
            return null;
        }
        return $this->db->transaction(function () use ($product) {
            $copy = array_diff_key($product, array_flip(['id', 'slug', 'legacy_wp_id', 'created_at', 'updated_at', 'published_at', 'sales_count', 'rating_avg', 'rating_count', 'review_note', 'is_featured', 'nica_score_verified']));
            $copy['name'] = mb_substr_safe($product['name'] . ' (Copy)', 255);
            $copy['status'] = 'draft';
            $copy['slug'] = $this->uniqueSlug($copy['name']);
            $id = $this->db->insert('products', $copy);
            foreach ($this->db->select('SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY sort_order, id', [$product['id']]) as $v) {
                $values = $this->db->select('SELECT attribute_id, attribute_value_id FROM variant_values WHERE variant_id = ?', [$v['id']]);
                $newId = $this->db->insert('product_variants', array_diff_key($v, array_flip(['id', 'legacy_wp_id', 'sku'])) + ['product_id' => $id, 'sku' => null]);
                foreach ($values as $vv) {
                    $this->db->insert('variant_values', ['variant_id' => $newId] + $vv);
                }
            }
            $this->db->query('INSERT INTO product_attribute_values (product_id, attribute_value_id, used_for_variants) SELECT ?, attribute_value_id, used_for_variants FROM product_attribute_values WHERE product_id = ?', [$id, $product['id']]);
            $this->db->query('INSERT INTO product_category (product_id, category_id) SELECT ?, category_id FROM product_category WHERE product_id = ?', [$id, $product['id']]);
            $this->db->query('INSERT INTO product_tag (product_id, tag_id) SELECT ?, tag_id FROM product_tag WHERE product_id = ?', [$id, $product['id']]);
            $this->db->query('INSERT INTO product_images (product_id, media_id, sort_order) SELECT ?, media_id, sort_order FROM product_images WHERE product_id = ?', [$id, $product['id']]);
            (new ProductIndexer($this->db))->refresh($id);
            return $id;
        });
    }

    /** Products are archived, not deleted: past orders and reviews keep pointing at them. */
    public function archive(int $vendorId, int $productId): bool
    {
        return $this->db->query("UPDATE products SET status = 'archived' WHERE id = ? AND vendor_id = ?", [$productId, $vendorId])->rowCount() === 1;
    }

    public function restore(int $vendorId, int $productId): bool
    {
        return $this->db->query("UPDATE products SET status = 'draft' WHERE id = ? AND vendor_id = ? AND status = 'archived'", [$productId, $vendorId])->rowCount() === 1;
    }

    /** Quick stock update from the product list. */
    public function setStock(int $vendorId, int $variantId, int $qty): bool
    {
        $ok = $this->db->query(
            "UPDATE product_variants v JOIN products p ON p.id = v.product_id SET v.manage_stock = 1, v.stock_qty = ?, v.stock_status = IF(? > 0, 'instock', 'outofstock')
             WHERE v.id = ? AND p.vendor_id = ?",
            [max(0, $qty), $qty, $variantId, $vendorId]
        )->rowCount() > 0;
        $productId = (int) $this->db->scalar('SELECT product_id FROM product_variants WHERE id = ?', [$variantId]);
        if ($productId) {
            (new ProductIndexer($this->db))->refresh($productId);
        }
        return $ok;
    }

    public function lowStock(int $vendorId, int $threshold = 3): array
    {
        return $this->db->select(
            "SELECT p.id AS product_id, p.name, v.id AS variant_id, v.stock_qty,
                    (SELECT GROUP_CONCAT(av.name ORDER BY a.sort_order SEPARATOR ' / ') FROM variant_values vv JOIN attribute_values av ON av.id = vv.attribute_value_id JOIN attributes a ON a.id = vv.attribute_id WHERE vv.variant_id = v.id) AS label
             FROM product_variants v JOIN products p ON p.id = v.product_id
             WHERE p.vendor_id = ? AND p.status = 'published' AND v.is_active = 1 AND v.manage_stock = 1 AND v.stock_qty <= ?
             ORDER BY v.stock_qty, p.name LIMIT 8",
            [$vendorId, $threshold]
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-') ?: 'product';
        $base = substr($base, 0, 180);
        $slug = $base;
        for ($i = 2; $this->db->scalar('SELECT 1 FROM products WHERE slug = ?', [$slug]) || $this->db->scalar('SELECT 1 FROM pages WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /** @return int[] the given ids that exist in $table, in the given order */
    private function existingIds(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $found = array_map('intval', array_column($this->db->select('SELECT id FROM ' . $this->db->ident($table) . ' WHERE id IN (' . implode(',', $ids) . ')'), 'id'));
        return array_values(array_intersect($ids, $found));
    }

    private function syncPivot(string $table, string $column, int $productId, array $ids): void
    {
        $this->db->delete($table, ['product_id' => $productId]);
        foreach ($ids as $id) {
            $this->db->insert($table, ['product_id' => $productId, $column => $id]);
        }
    }
}
