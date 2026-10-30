<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;

/**
 * Read side of the catalog: product listings with filters/sorting, product
 * detail, categories, vendors. Only published products of approved vendors
 * are ever visible here.
 */
final class Catalog
{
    public const PER_PAGE = 12;

    /** Sort keys as used in ?sort= on the live site. */
    public const SORTS = [
        'latest' => 'Latest',
        'menu' => 'Default sorting',
        'price' => 'Price: low to high',
        'price-desc' => 'Price: high to low',
        'popularity' => 'Popularity',
        'rating' => 'Average rating',
    ];

    private const VISIBLE = "p.status = 'published' AND v.status = 'approved' AND v.vacation_mode = 0";

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array{category?:int, tag?:int, vendor?:int, search?:string, values?:int[], sort?:string} $filters
     * @return array{items:array,total:int,page:int,pages:int}
     */
    public function products(array $filters, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = $this->where($filters);
        $order = match ($filters['sort'] ?? 'latest') {
            'menu' => 'p.sort_order ASC, p.name ASC',
            'price' => 'p.price_min_cents ASC, p.id DESC',
            'price-desc' => 'p.price_max_cents DESC, p.id DESC',
            'popularity' => 'p.sales_count DESC, p.published_at DESC',
            'rating' => 'p.rating_avg DESC, p.rating_count DESC, p.published_at DESC',
            'relevance' => 'relevance DESC, p.published_at DESC',
            default => 'p.published_at DESC, p.id DESC',
        };
        $select = $this->cardColumns();
        if (($filters['sort'] ?? '') === 'relevance' && !empty($filters['search'])) {
            $select .= ', MATCH(p.name, p.short_description, p.description) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance';
            array_unshift($params, $filters['search']);
        }

        $countParams = ($filters['sort'] ?? '') === 'relevance' && !empty($filters['search']) ? array_slice($params, 1) : $params;
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE {$where}", $countParams);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, $page);
        $items = $page > $pages ? [] : $this->db->select(
            "SELECT {$select} FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE {$where} ORDER BY {$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params,
        );
        app()->images()->prime(array_column($items, 'featured_media_id'));
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Product cards by id, in the given order. */
    public function cards(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT ' . $this->cardColumns() . ' FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE ' . self::VISIBLE
            . ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );
        $byId = array_column($rows, null, 'id');
        app()->images()->prime(array_column($rows, 'featured_media_id'));
        return array_values(array_filter(array_map(static fn ($id) => $byId[$id] ?? null, $ids)));
    }

    public function featured(int $limit): array
    {
        return $this->db->select(
            'SELECT ' . $this->cardColumns() . ' FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE ' . self::VISIBLE
            . ' AND p.is_featured = 1 ORDER BY p.published_at DESC LIMIT ' . $limit,
        );
    }

    public function related(array $product, int $limit = 4): array
    {
        $rows = $this->db->select(
            'SELECT ' . $this->cardColumns() . ' FROM products p JOIN vendors v ON v.id = p.vendor_id
             WHERE ' . self::VISIBLE . ' AND p.id <> ? AND p.id IN (
                SELECT pc.product_id FROM product_category pc WHERE pc.category_id IN (SELECT category_id FROM product_category WHERE product_id = ?)
                UNION SELECT pt.product_id FROM product_tag pt WHERE pt.tag_id IN (SELECT tag_id FROM product_tag WHERE product_id = ?))
             ORDER BY RAND() LIMIT ' . $limit,
            [$product['id'], $product['id'], $product['id']],
        );
        app()->images()->prime(array_column($rows, 'featured_media_id'));
        return $rows;
    }

    public function productBySlug(string $slug): ?array
    {
        return $this->db->first(
            'SELECT p.*, v.store_name AS vendor_name, v.slug AS vendor_slug, v.is_premier_partner, v.premier_number, v.logo_media_id AS vendor_logo,
                    v.rating_avg AS vendor_rating, v.rating_count AS vendor_rating_count, v.shipping_policy, v.refund_policy
             FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.slug = ? AND ' . self::VISIBLE,
            [$slug],
        );
    }

    /** Variants with their attribute values and effective prices (for the picker). */
    public function variants(int $productId): array
    {
        $variants = $this->db->select('SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY sort_order, id', [$productId]);
        $values = [];
        if ($variants) {
            $ids = array_column($variants, 'id');
            foreach ($this->db->select('SELECT variant_id, attribute_id, attribute_value_id FROM variant_values WHERE variant_id IN (' . implode(',', array_map('intval', $ids)) . ')') as $vv) {
                $values[(int) $vv['variant_id']][(int) $vv['attribute_id']] = (int) $vv['attribute_value_id'];
            }
        }
        $now = gmdate('Y-m-d H:i:s');
        return array_map(static fn (array $v) => [
            'id' => (int) $v['id'],
            'sku' => $v['sku'],
            'price' => ProductIndexer::effectivePrice($v, $now),
            'regular' => (int) $v['regular_price_cents'],
            'in_stock' => ProductIndexer::isPurchasable($v),
            'stock' => (int) $v['manage_stock'] ? (int) $v['stock_qty'] : null,
            'image' => $v['image_media_id'] ? (int) $v['image_media_id'] : null,
            'values' => $values[(int) $v['id']] ?? [],
        ], $variants);
    }

    /** Attributes offered by a product, with their values, in display order. */
    public function productAttributes(int $productId): array
    {
        $rows = $this->db->select(
            'SELECT a.id AS attribute_id, a.slug AS attribute_slug, a.name AS attribute_name, a.display, av.id, av.name, av.swatch, pav.used_for_variants
             FROM product_attribute_values pav JOIN attribute_values av ON av.id = pav.attribute_value_id JOIN attributes a ON a.id = av.attribute_id
             WHERE pav.product_id = ? ORDER BY a.sort_order, av.sort_order, av.name',
            [$productId],
        );
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) $r['attribute_id'];
            $out[$aid] ??= ['id' => $aid, 'slug' => $r['attribute_slug'], 'name' => $r['attribute_name'], 'display' => $r['display'], 'variation' => false, 'values' => []];
            $out[$aid]['variation'] = $out[$aid]['variation'] || (int) $r['used_for_variants'] === 1;
            $out[$aid]['values'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'swatch' => $r['swatch']];
        }
        return array_values($out);
    }

    public function images(int $productId): array
    {
        return array_map('intval', array_column($this->db->select('SELECT media_id FROM product_images WHERE product_id = ? ORDER BY sort_order', [$productId]), 'media_id'));
    }

    public function productCategories(int $productId): array
    {
        return $this->db->select(
            'SELECT c.id, c.name, c.path FROM product_categories c JOIN product_category pc ON pc.category_id = c.id WHERE pc.product_id = ? ORDER BY c.parent_id IS NOT NULL, c.sort_order, c.name',
            [$productId],
        );
    }

    public function reviews(int $productId): array
    {
        return $this->db->select("SELECT * FROM reviews WHERE product_id = ? AND status = 'approved' ORDER BY created_at DESC", [$productId]);
    }

    public function categoryByPath(string $path): ?array
    {
        return $this->db->first('SELECT * FROM product_categories WHERE path = ?', [trim($path, '/')]);
    }

    /** @return int[] the category and all its descendants */
    public function categoryTree(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];
        while ($frontier) {
            $children = array_map('intval', array_column($this->db->select('SELECT id FROM product_categories WHERE parent_id IN (' . implode(',', $frontier) . ')'), 'id'));
            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }
        return $ids;
    }

    /** Top-level categories (optionally only those flagged for the homepage). */
    public function topCategories(bool $homeOnly = false): array
    {
        $rows = $this->db->select(
            'SELECT c.* FROM product_categories c WHERE c.parent_id IS NULL' . ($homeOnly ? ' AND c.show_on_home = 1' : '')
            . ($homeOnly ? ' ORDER BY c.id' : ' ORDER BY c.sort_order, c.id'),
        );
        app()->images()->prime(array_column($rows, 'image_media_id'));
        return $rows;
    }

    public function hairTypes(int $limit): array
    {
        $rows = $this->db->select('SELECT * FROM product_tags WHERE is_hair_type = 1 ORDER BY sort_order, name LIMIT ' . $limit);
        app()->images()->prime(array_column($rows, 'image_media_id'));
        return $rows;
    }

    public function tagBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM product_tags WHERE slug = ?', [$slug]);
    }

    public function vendorBySlug(string $slug): ?array
    {
        return $this->db->first("SELECT * FROM vendors WHERE slug = ? AND status = 'approved'", [$slug]);
    }

    public function vendors(string $sort = 'recent', string $search = ''): array
    {
        $order = match ($sort) {
            'name' => 'v.store_name ASC',
            'rating' => 'v.rating_avg DESC, v.rating_count DESC',
            'featured' => 'v.is_featured DESC, v.approved_at DESC',
            default => 'v.approved_at DESC, v.id DESC',
        };
        $where = "v.status = 'approved'";
        $params = [];
        if ($search !== '') {
            $where .= ' AND v.store_name LIKE ?';
            $params[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        return $this->db->select(
            "SELECT v.*, (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.status = 'published') AS product_count
             FROM vendors v WHERE {$where} ORDER BY {$order}",
            $params,
        );
    }

    public function topReviews(int $limit): array
    {
        return $this->db->select(
            "SELECT r.*, p.name AS product_name, p.slug AS product_slug FROM reviews r JOIN products p ON p.id = r.product_id
             WHERE r.status = 'approved' AND p.status = 'published'
             ORDER BY r.is_featured DESC, r.rating DESC, r.created_at DESC LIMIT " . $limit,
        );
    }

    public function vendorReviews(int $vendorId): array
    {
        return $this->db->select(
            "SELECT r.*, p.name AS product_name, p.slug AS product_slug FROM reviews r JOIN products p ON p.id = r.product_id
             WHERE r.vendor_id = ? AND r.status = 'approved' ORDER BY r.created_at DESC",
            [$vendorId],
        );
    }

    public function productIdsByLegacy(array $legacyIds): array
    {
        $legacyIds = array_values(array_filter(array_map('intval', $legacyIds)));
        if ($legacyIds === []) {
            return [];
        }
        $rows = $this->db->select('SELECT id, legacy_wp_id FROM products WHERE legacy_wp_id IN (' . implode(',', $legacyIds) . ')');
        $map = array_column($rows, 'id', 'legacy_wp_id');
        return array_values(array_filter(array_map(static fn ($l) => isset($map[$l]) ? (int) $map[$l] : null, $legacyIds)));
    }

    private function cardColumns(): string
    {
        return 'p.id, p.slug, p.name, p.type, p.featured_media_id, p.price_min_cents, p.price_max_cents, p.regular_min_cents, p.regular_max_cents,
                p.on_sale, p.in_stock, p.rating_avg, p.rating_count, p.published_at,
                v.store_name AS vendor_name, v.slug AS vendor_slug, v.is_premier_partner';
    }

    /** @return array{0:string,1:array} */
    private function where(array $f): array
    {
        $where = [self::VISIBLE];
        $params = [];
        if (!empty($f['category'])) {
            $tree = $this->categoryTree((int) $f['category']);
            $where[] = 'EXISTS (SELECT 1 FROM product_category pc WHERE pc.product_id = p.id AND pc.category_id IN (' . implode(',', $tree) . '))';
        }
        if (!empty($f['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM product_tag pt WHERE pt.product_id = p.id AND pt.tag_id = ?)';
            $params[] = (int) $f['tag'];
        }
        if (!empty($f['vendor'])) {
            $where[] = 'p.vendor_id = ?';
            $params[] = (int) $f['vendor'];
        }
        if (!empty($f['search'])) {
            // Full-text for relevance plus a LIKE fallback so short words and partial names still match.
            $where[] = '(MATCH(p.name, p.short_description, p.description) AGAINST (? IN NATURAL LANGUAGE MODE) OR p.name LIKE ?)';
            $params[] = $f['search'];
            $params[] = '%' . addcslashes($f['search'], '%_\\') . '%';
        }
        foreach ($f['value_groups'] ?? [] as $group) {
            $group = array_values(array_filter(array_map('intval', $group)));
            if ($group) {
                $where[] = 'EXISTS (SELECT 1 FROM product_attribute_values pav WHERE pav.product_id = p.id AND pav.attribute_value_id IN (' . implode(',', $group) . '))';
            }
        }
        return [implode(' AND ', $where), $params];
    }

    /** [name, path] crumbs for a category and its parents, root first. */
    public function categoryTrail(?int $categoryId): array
    {
        $path = $categoryId ? (string) $this->db->scalar('SELECT path FROM product_categories WHERE id = ?', [$categoryId]) : '';
        if ($path === '') {
            return [];
        }
        $parts = explode('/', $path);
        $prefixes = [];
        foreach ($parts as $i => $part) {
            $prefixes[] = implode('/', array_slice($parts, 0, $i + 1));
        }
        $names = [];
        foreach ($this->db->select('SELECT path, name FROM product_categories WHERE path IN (' . implode(',', array_fill(0, count($prefixes), '?')) . ')', $prefixes) as $r) {
            $names[$r['path']] = $r['name'];
        }
        return array_values(array_map(static fn ($p) => [$names[$p] ?? $p, '/collection/' . $p . '/'], array_filter($prefixes, static fn ($p) => isset($names[$p]))));
    }
}
