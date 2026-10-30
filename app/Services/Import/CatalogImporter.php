<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Database;
use App\Services\Catalog\ProductIndexer;

/**
 * Imports users, Dokan vendors and the WooCommerce catalog (categories, tags,
 * attributes, products, variations, images, reviews) plus the quiz config.
 * Idempotent: rows are matched on legacy_wp_id.
 */
final class CatalogImporter
{
    /** WordPress role => new role. */
    private const ROLES = ['administrator' => 'admin', 'shop_manager' => 'staff', 'seller' => 'vendor', 'customer' => 'customer', 'subscriber' => 'customer'];

    /** Homepage tiles on the live site (top-level categories in this order). */
    private const HOME_CATEGORIES = [21, 42, 49, 51, 52, 69];

    /** "Shop by hair type" order and labels on the live homepage. */
    private const HAIR_TYPES = [83 => ['Soft movement', 0], 82 => ['Sleek & smooth', 1], 93 => ['Rich texture', 2], 84 => ['Defined coils', 3]];

    private const COLOR_SWATCHES = ['black' => '#111111', 'blonde' => '#d9bd7f', 'brown' => '#5c3a21', 'gray' => '#9a9a9a', 'red' => '#a41f1f',
        'dark brown' => '#3b2416', 'medium brown' => '#6b4226', 'light warm brown' => '#a0703f', 'light brown' => '#8b5a2b'];

    private array $map = ['user' => [], 'vendor' => [], 'media' => [], 'category' => [], 'tag' => [], 'value' => [], 'product' => []];
    private array $attributeIds = [];
    /** @var callable(string):void */
    private $log;

    public function __construct(
        private WordPressSource $wp,
        private Database $db,
        private WordPressImporter $content,
        private string $contentDir,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {
        };
    }

    public function run(array $steps): void
    {
        foreach ($steps as $step) {
            ($this->log)("\n== {$step}");
            match ($step) {
                'users' => $this->importUsers(),
                'vendors' => $this->importVendors(),
                'taxonomies' => $this->importTaxonomies(),
                'products' => $this->importProducts(),
                'reviews' => $this->importReviews(),
                'quiz' => $this->importQuiz(),
                'coupons' => $this->importCoupons(),
                'orders' => $this->importOrders(),
                'balances' => $this->importBalances(),
                default => throw new \InvalidArgumentException("Unknown catalog step '{$step}'."),
            };
        }
    }

    /* ------------------------------------------------------------------ users */

    public function importUsers(): void
    {
        $count = 0;
        foreach ($this->wp->db()->select("SELECT * FROM {$this->wp->table('users')} ORDER BY ID") as $u) {
            $meta = $this->userMeta((int) $u['ID']);
            $email = strtolower(trim((string) $u['user_email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            // An account with the same email may already exist (e.g. an admin created locally).
            $existing = $this->db->scalar('SELECT id FROM users WHERE email = ? AND (legacy_wp_id IS NULL OR legacy_wp_id <> ?)', [$email, $u['ID']]);
            $row = [
                'email' => $email,
                'first_name' => mb_substr_safe((string) ($meta['first_name'] ?? ''), 100),
                'last_name' => mb_substr_safe((string) ($meta['last_name'] ?? ''), 100),
                'display_name' => mb_substr_safe((string) ($u['display_name'] ?: $u['user_login']), 191),
                'username' => mb_substr_safe((string) $u['user_login'], 60) ?: null,
                'phone' => mb_substr_safe((string) ($meta['billing_phone'] ?? ''), 40) ?: null,
                'billing_address' => $this->wcAddress($meta, 'billing'),
                'shipping_address' => $this->wcAddress($meta, 'shipping'),
                'email_verified_at' => $u['user_registered'],
                'legacy_wp_id' => (int) $u['ID'],
                'created_at' => $u['user_registered'],
            ];
            if ($existing !== null) {
                $id = (int) $existing;
                $this->db->update('users', ['legacy_wp_id' => (int) $u['ID']], ['id' => $id]);
            } else {
                // The WordPress hash is kept as-is and upgraded to Argon2id at first login.
                $row['password_hash'] = (string) $u['user_pass'];
                $id = $this->content->upsert('users', $row, ['legacy_wp_id' => $row['legacy_wp_id']]);
            }
            $caps = $meta[$this->wp->table('capabilities')] ?? [];
            foreach (is_array($caps) ? array_keys(array_filter($caps)) : [] as $wpRole) {
                if (isset(self::ROLES[$wpRole])) {
                    $this->db->query('INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, ?)', [$id, self::ROLES[$wpRole]]);
                }
            }
            $this->db->query("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'customer')", [$id]);
            // Points balance from the old Points & Rewards plugin, carried over once.
            $points = (int) ($meta['wps_wpr_points'] ?? 0);
            if ($points > 0 && !$this->db->scalar("SELECT 1 FROM points_ledger WHERE user_id = ? AND reason = 'import'", [$id])) {
                (new \App\Services\Points($this->db))->add($id, $points, 'import', null, 'Balance carried over from the previous site');
            }
            $count++;
        }
        ($this->log)("{$count} users");
    }

    /* ---------------------------------------------------------------- vendors */

    public function importVendors(): void
    {
        $this->loadMaps();
        $rows = $this->wp->db()->select(
            "SELECT u.* FROM {$this->wp->table('users')} u JOIN {$this->wp->table('usermeta')} m ON m.user_id = u.ID AND m.meta_key = ?
             WHERE m.meta_value LIKE '%\"seller\"%' ORDER BY u.user_registered, u.ID",
            [$this->wp->table('capabilities')],
        );
        $premier = 0;
        foreach ($rows as $u) {
            $meta = $this->userMeta((int) $u['ID']);
            $profile = is_array($meta['dokan_profile_settings'] ?? null) ? $meta['dokan_profile_settings'] : [];
            $approved = ($meta['dokan_enable_selling'] ?? '') === 'yes';
            $address = array_filter((array) ($profile['address'] ?? []), static fn ($v) => is_string($v) && $v !== '');
            $social = array_filter((array) ($profile['social'] ?? []), static fn ($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL));
            $row = [
                'user_id' => $this->map['user'][(int) $u['ID']] ?? null,
                'slug' => (string) $u['user_nicename'],
                'store_name' => (string) ($profile['store_name'] ?? $meta['dokan_store_name'] ?? $u['display_name']),
                'status' => $approved ? 'approved' : 'pending',
                'phone' => mb_substr_safe((string) ($profile['phone'] ?? ''), 40) ?: null,
                'public_email' => ($profile['show_email'] ?? 'no') === 'yes' ? $u['user_email'] : null,
                'address' => $address ? json_encode($address) : null,
                'social' => $social ? json_encode($social, JSON_UNESCAPED_SLASHES) : null,
                'logo_media_id' => $this->mediaId((int) ($profile['gravatar'] ?? 0)),
                'banner_media_id' => $this->mediaId((int) ($profile['banner'] ?? 0)),
                // The first 50 approved vendors are founding "Premier Partners".
                'is_premier_partner' => $approved && $premier < 50 ? 1 : 0,
                'premier_number' => $approved && $premier < 50 ? ++$premier : null,
                'is_featured' => ($meta['dokan_feature_seller'] ?? 'no') === 'yes' ? 1 : 0,
                'commission_rate' => is_numeric($meta['dokan_admin_percentage'] ?? null) ? (float) $meta['dokan_admin_percentage'] : null,
                'legacy_wp_id' => (int) $u['ID'],
                'approved_at' => $approved ? $u['user_registered'] : null,
                'created_at' => $u['user_registered'],
            ];
            $this->content->upsert('vendors', $row, ['legacy_wp_id' => $row['legacy_wp_id']]);
        }
        ($this->log)(count($rows) . " vendors ({$premier} premier partners)");
    }

    /* ------------------------------------------------------------- taxonomies */

    public function importTaxonomies(): void
    {
        $this->loadMaps();

        // Categories, parents first so paths can be built.
        $terms = $this->wp->terms('product_cat');
        $byId = array_column($terms, null, 'term_id');
        $depth = function (array $t) use (&$depth, $byId): int {
            return (int) $t['parent'] && isset($byId[(int) $t['parent']]) ? 1 + $depth($byId[(int) $t['parent']]) : 0;
        };
        usort($terms, fn ($a, $b) => [$depth($a), (int) $a['term_id']] <=> [$depth($b), (int) $b['term_id']]);
        foreach ($terms as $t) {
            if ($t['slug'] === 'uncategorized') {
                continue;
            }
            $meta = $this->wp->termMeta((int) $t['term_id']);
            $parentId = (int) $t['parent'] ? ($this->map['category'][(int) $t['parent']] ?? null) : null;
            $parentPath = $parentId ? (string) $this->db->scalar('SELECT path FROM product_categories WHERE id = ?', [$parentId]) : '';
            $id = $this->content->upsert('product_categories', [
                'parent_id' => $parentId,
                'slug' => $t['slug'],
                'path' => ltrim($parentPath . '/' . $t['slug'], '/'),
                'name' => html_entity_decode((string) $t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => trim(strip_tags((string) $t['description'])) ?: null,
                'image_media_id' => $this->mediaId((int) ($meta['thumbnail_id'] ?? 0)),
                'sort_order' => (int) ($meta['order'] ?? 0),
                'show_on_home' => in_array((int) $t['term_id'], self::HOME_CATEGORIES, true) ? 1 : 0,
                'seo_title' => $this->content->seoText($meta['rank_math_title'] ?? null, ['term' => $t['name']]),
                'seo_description' => $this->content->seoText($meta['rank_math_description'] ?? null, ['term' => $t['name'], 'term_description' => strip_tags((string) $t['description'])]),
                'legacy_wp_id' => (int) $t['term_id'],
            ], ['legacy_wp_id' => (int) $t['term_id']]);
            $this->map['category'][(int) $t['term_id']] = $id;
        }

        foreach ($this->wp->terms('product_tag') as $t) {
            $meta = $this->wp->termMeta((int) $t['term_id']);
            [$subtitle, $order] = self::HAIR_TYPES[(int) $t['term_id']] ?? [null, 99];
            $this->map['tag'][(int) $t['term_id']] = $this->content->upsert('product_tags', [
                'slug' => $t['slug'],
                'name' => html_entity_decode((string) $t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'subtitle' => $subtitle,
                'description' => trim(strip_tags((string) $t['description'])) ?: null,
                'image_media_id' => $this->mediaId((int) ($meta['thumbnail_id'] ?? 0)),
                'is_hair_type' => isset(self::HAIR_TYPES[(int) $t['term_id']]) ? 1 : 0,
                'sort_order' => $order,
                'legacy_wp_id' => (int) $t['term_id'],
            ], ['legacy_wp_id' => (int) $t['term_id']]);
        }

        // Attributes: Length (buttons) and Color (swatches).
        foreach ($this->wp->db()->select("SELECT * FROM {$this->wp->table('woocommerce_attribute_taxonomies')} ORDER BY attribute_id") as $i => $a) {
            $slug = (string) $a['attribute_name'];
            $this->attributeIds[$slug] = $this->upsertAttribute($slug, (string) $a['attribute_label'], $slug === 'color' ? 'swatches' : 'buttons', $i);
            foreach ($this->wp->terms('pa_' . $slug) as $t) {
                $name = html_entity_decode((string) $t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->map['value'][(int) $t['term_id']] = $this->upsertValue($this->attributeIds[$slug], $name, (int) $t['term_id']);
            }
        }
        ($this->log)(count($this->map['category']) . ' categories, ' . count($this->map['tag']) . ' tags, ' . count($this->map['value']) . ' attribute values');
    }

    /* --------------------------------------------------------------- products */

    public function importProducts(): void
    {
        $this->loadMaps();
        $indexer = new ProductIndexer($this->db);
        $types = [];
        foreach ($this->wp->terms('product_type') as $t) {
            $types[(int) $t['term_id']] = $t['slug'];
        }
        $count = $variantCount = 0;

        foreach ($this->wp->posts('product', ['publish', 'pending', 'draft', 'private']) as $post) {
            $meta = $this->wp->postMeta((int) $post['ID']);
            $vendorId = $this->map['vendor'][(int) $post['post_author']] ?? null;
            if ($vendorId === null) {
                ($this->log)("  ! product {$post['ID']} has no vendor, skipped");
                continue;
            }
            $typeIds = $this->wp->objectTermIds((int) $post['ID'], 'product_type');
            $type = ($types[$typeIds[0] ?? 0] ?? 'simple') === 'variable' ? 'variable' : 'simple';
            $catIds = array_values(array_filter(array_map(fn ($id) => $this->map['category'][$id] ?? null, $this->wp->objectTermIds((int) $post['ID'], 'product_cat'))));
            $primary = $this->map['category'][(int) ($meta['rank_math_primary_product_cat'] ?? 0)] ?? ($catIds[0] ?? null);

            $productId = $this->content->upsert('products', [
                'vendor_id' => $vendorId,
                'slug' => (string) $post['post_name'],
                'name' => html_entity_decode((string) $post['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'type' => $type,
                'status' => match ($post['post_status']) { 'publish' => 'published', 'pending' => 'pending', default => 'draft' },
                'short_description' => $this->cleanText((string) $post['post_excerpt']) ?: null,
                'description' => $this->cleanText((string) $post['post_content']) ?: null,
                'featured_media_id' => $this->mediaId((int) ($meta['_thumbnail_id'] ?? 0)),
                'primary_category_id' => $primary,
                'sales_count' => (int) ($meta['total_sales'] ?? 0),
                'sort_order' => (int) $post['menu_order'],
                'seo_title' => $this->content->seoText($meta['rank_math_title'] ?? null, ['title' => $post['post_title']]),
                'seo_description' => $this->content->seoText($meta['rank_math_description'] ?? null, ['title' => $post['post_title'], 'excerpt' => strip_tags((string) $post['post_excerpt'])]),
                'legacy_wp_id' => (int) $post['ID'],
                'published_at' => $post['post_date_gmt'] !== '0000-00-00 00:00:00' ? $post['post_date_gmt'] : $post['post_date'],
                'created_at' => $post['post_date'],
            ], ['legacy_wp_id' => (int) $post['ID']]);
            $this->map['product'][(int) $post['ID']] = $productId;
            $classIds = $this->wp->objectTermIds((int) $post['ID'], 'product_shipping_class');
            $class = $classIds ? $this->wp->db()->scalar("SELECT slug FROM {$this->wp->table('terms')} WHERE term_id = ?", [$classIds[0]]) : null;
            $this->db->update('products', ['shipping_class' => $class ?: null], ['id' => $productId]);

            // Relations are rebuilt on every run.
            foreach (['product_category', 'product_tag', 'product_images', 'product_attribute_values'] as $table) {
                $this->db->delete($table, ['product_id' => $productId]);
            }
            foreach ($catIds as $cid) {
                $this->db->query('INSERT IGNORE INTO product_category (product_id, category_id) VALUES (?, ?)', [$productId, $cid]);
            }
            foreach ($this->wp->objectTermIds((int) $post['ID'], 'product_tag') as $tid) {
                if (isset($this->map['tag'][$tid])) {
                    $this->db->query('INSERT IGNORE INTO product_tag (product_id, tag_id) VALUES (?, ?)', [$productId, $this->map['tag'][$tid]]);
                }
            }
            $gallery = array_filter(array_map('intval', explode(',', (string) ($meta['_product_image_gallery'] ?? ''))));
            foreach (array_values(array_unique(array_merge([(int) ($meta['_thumbnail_id'] ?? 0)], $gallery))) as $i => $wpMedia) {
                if ($mediaId = $this->mediaId($wpMedia)) {
                    $this->db->query('INSERT IGNORE INTO product_images (product_id, media_id, sort_order) VALUES (?, ?, ?)', [$productId, $mediaId, $i]);
                }
            }

            // Attribute values offered by the product (taxonomy + custom attributes).
            $attributes = is_array($meta['_product_attributes'] ?? null) ? $meta['_product_attributes'] : [];
            $customValues = [];
            foreach ($attributes as $key => $attr) {
                $forVariants = (int) ($attr['is_variation'] ?? 0);
                if ((int) ($attr['is_taxonomy'] ?? 0) === 1 && str_starts_with((string) $key, 'pa_')) {
                    foreach ($this->wp->objectTermIds((int) $post['ID'], (string) $key) as $termId) {
                        if (isset($this->map['value'][$termId])) {
                            $this->db->query('INSERT IGNORE INTO product_attribute_values (product_id, attribute_value_id, used_for_variants) VALUES (?, ?, ?)', [$productId, $this->map['value'][$termId], $forVariants]);
                        }
                    }
                } else {
                    // Custom attribute ("Color: Dark Brown | Light Brown") mapped onto the shared attribute.
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) ($attr['name'] ?? $key)));
                    $attributeId = $this->attributeIds[$slug] ?? $this->upsertAttribute($slug, (string) ($attr['name'] ?? $key), 'buttons', 10);
                    $this->attributeIds[$slug] = $attributeId;
                    foreach (array_filter(array_map('trim', explode('|', (string) ($attr['value'] ?? '')))) as $name) {
                        $valueId = $this->upsertValue($attributeId, $name, null);
                        $customValues[$slug][strtolower($name)] = $valueId;
                        $this->db->query('INSERT IGNORE INTO product_attribute_values (product_id, attribute_value_id, used_for_variants) VALUES (?, ?, ?)', [$productId, $valueId, $forVariants]);
                    }
                }
            }

            // Variants: a simple product gets one variant from its own meta.
            $existingVariants = array_column($this->db->select('SELECT id, legacy_wp_id FROM product_variants WHERE product_id = ?', [$productId]), 'id', 'legacy_wp_id');
            $sources = $type === 'variable'
                ? $this->wp->db()->select("SELECT * FROM {$this->wp->table('posts')} WHERE post_type = 'product_variation' AND post_parent = ? AND post_status IN ('publish','private') ORDER BY menu_order, ID", [$post['ID']])
                : [$post];
            $seen = [];
            foreach ($sources as $order => $vPost) {
                $vMeta = $vPost['ID'] === $post['ID'] ? $meta : $this->wp->postMeta((int) $vPost['ID']);
                $variantId = $this->content->upsert('product_variants', [
                    'product_id' => $productId,
                    'sku' => mb_substr_safe((string) ($vMeta['_sku'] ?? ''), 100) ?: null,
                    'regular_price_cents' => self::cents($vMeta['_regular_price'] ?? $vMeta['_price'] ?? 0),
                    'sale_price_cents' => isset($vMeta['_sale_price']) && $vMeta['_sale_price'] !== '' ? self::cents($vMeta['_sale_price']) : null,
                    'sale_starts_at' => self::date($vMeta['_sale_price_dates_from'] ?? null),
                    'sale_ends_at' => self::date($vMeta['_sale_price_dates_to'] ?? null),
                    'manage_stock' => ($vMeta['_manage_stock'] ?? 'no') === 'yes' ? 1 : 0,
                    'stock_qty' => is_numeric($vMeta['_stock'] ?? null) ? (int) $vMeta['_stock'] : null,
                    'stock_status' => in_array($vMeta['_stock_status'] ?? '', ['instock', 'outofstock', 'onbackorder'], true) ? $vMeta['_stock_status'] : 'instock',
                    'image_media_id' => $vPost['ID'] === $post['ID'] ? null : ($this->mediaId((int) ($vMeta['_thumbnail_id'] ?? 0))),
                    'is_active' => 1,
                    'sort_order' => $order,
                    'legacy_wp_id' => (int) $vPost['ID'],
                ], ['legacy_wp_id' => (int) $vPost['ID']]);
                $seen[] = $variantId;
                $variantCount++;

                $this->db->delete('variant_values', ['variant_id' => $variantId]);
                if ($type !== 'variable') {
                    continue;
                }
                foreach ($vMeta as $key => $value) {
                    if (!str_starts_with((string) $key, 'attribute_') || $value === '' || $value === null) {
                        continue; // empty = "any value"
                    }
                    $attrKey = substr((string) $key, 10);
                    if (str_starts_with($attrKey, 'pa_')) {
                        $termId = (int) $this->wp->db()->scalar(
                            "SELECT t.term_id FROM {$this->wp->table('terms')} t JOIN {$this->wp->table('term_taxonomy')} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = ? AND t.slug = ?",
                            [$attrKey, $value],
                        );
                        $valueId = $this->map['value'][$termId] ?? null;
                        $attributeId = $this->attributeIds[substr($attrKey, 3)] ?? null;
                    } else {
                        $attributeId = $this->attributeIds[$attrKey] ?? null;
                        $valueId = $customValues[$attrKey][strtolower((string) $value)] ?? null;
                    }
                    if ($valueId && $attributeId) {
                        $this->db->insert('variant_values', ['variant_id' => $variantId, 'attribute_id' => $attributeId, 'attribute_value_id' => $valueId]);
                    }
                }
            }
            // Variations removed in WordPress are deactivated here (orders may reference them).
            foreach ($existingVariants as $legacy => $id) {
                if (!in_array((int) $id, $seen, true)) {
                    $this->db->update('product_variants', ['is_active' => 0], ['id' => (int) $id]);
                }
            }
            $indexer->refresh($productId);
            $count++;
        }

        // "Most-loved Products" on the live homepage: latest 4 from The NiCa Collection's "Others" category.
        $this->db->query('UPDATE products SET is_featured = 0');
        $nica = $this->map['vendor'][33] ?? 0;
        $others = $this->map['category'][42] ?? 0;
        $this->db->query(
            "UPDATE products p JOIN (SELECT p2.id FROM products p2 JOIN product_category pc ON pc.product_id = p2.id
               WHERE p2.vendor_id = ? AND pc.category_id = ? AND p2.status = 'published' ORDER BY p2.published_at DESC LIMIT 4) f ON f.id = p.id
             SET p.is_featured = 1",
            [$nica, $others],
        );
        ($this->log)("{$count} products, {$variantCount} variants");
    }

    /* ---------------------------------------------------------------- reviews */

    public function importReviews(): void
    {
        $this->loadMaps();
        $indexer = new ProductIndexer($this->db);
        $count = 0;
        $touched = [];
        $rows = $this->wp->db()->select("SELECT * FROM {$this->wp->table('comments')} WHERE comment_type = 'review' ORDER BY comment_ID");
        foreach ($rows as $c) {
            $productId = $this->map['product'][(int) $c['comment_post_ID']] ?? null;
            if ($productId === null) {
                continue;
            }
            $meta = [];
            foreach ($this->wp->db()->select("SELECT meta_key, meta_value FROM {$this->wp->table('commentmeta')} WHERE comment_id = ?", [$c['comment_ID']]) as $m) {
                $meta[$m['meta_key']] = $m['meta_value'];
            }
            $rating = max(1, min(5, (int) ($meta['rating'] ?? 5)));
            $this->content->upsert('reviews', [
                'product_id' => $productId,
                'vendor_id' => (int) $this->db->scalar('SELECT vendor_id FROM products WHERE id = ?', [$productId]),
                'user_id' => $this->map['user'][(int) $c['user_id']] ?? null,
                'author_name' => mb_substr_safe(trim((string) $c['comment_author']) ?: 'Customer', 191),
                'author_email' => filter_var($c['comment_author_email'], FILTER_VALIDATE_EMAIL) ? strtolower($c['comment_author_email']) : null,
                'rating' => $rating,
                'body' => trim(strip_tags(html_entity_decode((string) $c['comment_content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                'status' => match ((string) $c['comment_approved']) { '1' => 'approved', 'spam' => 'spam', 'trash' => 'rejected', default => 'pending' },
                'is_verified_buyer' => (int) ($meta['verified'] ?? 0),
                'ip' => $c['comment_author_IP'] ?: null,
                'legacy_wp_id' => (int) $c['comment_ID'],
                'created_at' => $c['comment_date_gmt'] !== '0000-00-00 00:00:00' ? $c['comment_date_gmt'] : $c['comment_date'],
            ], ['legacy_wp_id' => (int) $c['comment_ID']]);
            $touched[$productId] = true;
            $count++;
        }
        foreach (array_keys($touched) as $productId) {
            $indexer->refreshRatings($productId);
        }
        ($this->log)("{$count} reviews");
    }

    /* ------------------------------------------------------------------- quiz */

    public function importQuiz(): void
    {
        $this->loadMaps();
        $definition = require $this->contentDir . '/quiz.php';
        $media = fn (?int $id) => $id ? (string) $this->db->scalar('SELECT path FROM media WHERE id = ?', [$id]) : null;

        foreach ($definition['steps'] as &$step) {
            foreach ($step['options'] as &$option) {
                $legacy = (array) $option['legacy'];
                if ($step['source'] === 'category') {
                    $id = $this->map['category'][$legacy[0]] ?? null;
                    $image = $option['image'] ?? $media($id ? (int) $this->db->scalar('SELECT image_media_id FROM product_categories WHERE id = ?', [$id]) : null);
                    $option['ids'] = array_values(array_filter([$id]));
                    $option['children'] = array_values(array_filter(array_map(function (int $childLegacy) {
                        $cid = $this->map['category'][$childLegacy] ?? null;
                        return $cid ? ['ids' => [$cid], 'name' => (string) $this->db->scalar('SELECT name FROM product_categories WHERE id = ?', [$cid])] : null;
                    }, $option['children'] ?? [])));
                    $option['image'] = $image;
                } elseif ($step['source'] === 'tag') {
                    $id = $this->map['tag'][$legacy[0]] ?? null;
                    $option['ids'] = array_values(array_filter([$id]));
                    $option['image'] = $option['image'] ?? $media($id ? (int) $this->db->scalar('SELECT image_media_id FROM product_tags WHERE id = ?', [$id]) : null);
                } else {
                    $option['ids'] = array_values(array_filter(array_map(fn ($l) => $this->map['value'][$l] ?? null, $legacy)));
                }
                unset($option['legacy']);
            }
        }
        unset($step, $option);
        $this->db->query(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['quiz', json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
        ($this->log)('quiz with ' . count($definition['steps']) . ' steps');
    }

    /* ---------------------------------------------------------------- coupons */

    public function importCoupons(): void
    {
        $this->loadMaps();
        $bearer = ['default' => 'shared', 'shared_coupon' => 'shared', 'from_vendor' => 'vendor', 'from_admin' => 'platform'];
        $count = 0;
        foreach ($this->wp->posts('shop_coupon', ['publish', 'draft', 'pending', 'private']) as $post) {
            $m = $this->wp->postMeta((int) $post['ID']);
            $ids = static fn ($v) => array_values(array_filter(array_map('intval', is_array($v) ? $v : explode(',', (string) $v))));
            $mapIds = fn (array $legacy, string $kind) => array_values(array_filter(array_map(fn ($id) => $this->map[$kind][$id] ?? null, $legacy)));
            $vendorId = $this->map['vendor'][(int) $post['post_author']] ?? null;
            $type = in_array($m['discount_type'] ?? '', ['percent', 'fixed_cart', 'fixed_product'], true) ? $m['discount_type'] : 'percent';
            $emails = array_values(array_filter(array_map('strtolower', (array) ($m['customer_email'] ?? []))));
            $json = static fn (array $a) => $a ? json_encode($a) : null;
            $this->content->upsert('coupons', [
                'code' => mb_substr_safe(trim((string) $post['post_title']), 64),
                'description' => mb_substr_safe(trim((string) $post['post_excerpt']), 255) ?: null,
                'type' => $type,
                'amount' => round((float) ($m['coupon_amount'] ?? 0), 2),
                'vendor_id' => $vendorId,
                'cost_bearer' => $vendorId ? 'vendor' : ($bearer[$m['coupon_commissions_type'] ?? 'default'] ?? 'shared'),
                'free_shipping' => ($m['free_shipping'] ?? 'no') === 'yes' ? 1 : 0,
                'individual_use' => ($m['individual_use'] ?? 'no') === 'yes' ? 1 : 0,
                'exclude_sale_items' => ($m['exclude_sale_items'] ?? 'no') === 'yes' ? 1 : 0,
                'minimum_cents' => is_numeric($m['minimum_amount'] ?? null) && (float) $m['minimum_amount'] > 0 ? (int) round((float) $m['minimum_amount'] * 100) : null,
                'maximum_cents' => is_numeric($m['maximum_amount'] ?? null) && (float) $m['maximum_amount'] > 0 ? (int) round((float) $m['maximum_amount'] * 100) : null,
                'usage_limit' => (int) ($m['usage_limit'] ?? 0) ?: null,
                'usage_limit_per_user' => (int) ($m['usage_limit_per_user'] ?? 0) ?: null,
                'usage_count' => (int) ($m['usage_count'] ?? 0),
                'product_ids' => $json($mapIds($ids($m['product_ids'] ?? ''), 'product')),
                'excluded_product_ids' => $json($mapIds($ids($m['exclude_product_ids'] ?? ''), 'product')),
                'category_ids' => $json($mapIds($ids($m['product_categories'] ?? []), 'category')),
                'excluded_category_ids' => $json($mapIds($ids($m['exclude_product_categories'] ?? []), 'category')),
                'vendor_ids' => $json($mapIds($ids($m['coupons_vendors_ids'] ?? ''), 'vendor')),
                'allowed_emails' => $json($emails),
                'status' => $post['post_status'] === 'publish' ? 'active' : 'disabled',
                'expires_at' => is_numeric($m['date_expires'] ?? null) ? gmdate('Y-m-d H:i:s', (int) $m['date_expires']) : null,
                'legacy_wp_id' => (int) $post['ID'],
                'created_at' => $post['post_date_gmt'] !== '0000-00-00 00:00:00' ? $post['post_date_gmt'] : $post['post_date'],
            ], ['legacy_wp_id' => (int) $post['ID']]);
            $count++;
        }
        ($this->log)("{$count} coupons");
    }

    /* ----------------------------------------------------------------- orders */

    /** Historical WooCommerce (HPOS) orders, split per vendor like Dokan did. Read-only history. */
    public function importOrders(): void
    {
        $this->loadMaps();
        $wp = $this->wp->db();
        $t = fn (string $name) => $this->wp->table($name);
        $statusMap = ['wc-completed' => 'completed', 'wc-processing' => 'processing', 'wc-on-hold' => 'on-hold', 'wc-cancelled' => 'cancelled',
            'wc-refunded' => 'refunded', 'wc-failed' => 'failed', 'wc-pending' => 'pending'];
        $count = 0;
        foreach ($wp->select("SELECT * FROM {$t('wc_orders')} WHERE type = 'shop_order' ORDER BY id") as $o) {
            if (!isset($statusMap[$o['status']])) {
                continue; // trash / checkout drafts
            }
            $op = $wp->first("SELECT * FROM {$t('wc_order_operational_data')} WHERE order_id = ?", [$o['id']]) ?? [];
            $meta = [];
            foreach ($wp->select("SELECT meta_key, meta_value FROM {$t('wc_orders_meta')} WHERE order_id = ?", [$o['id']]) as $row) {
                $meta[$row['meta_key']] ??= $row['meta_value'];
            }
            $addresses = [];
            foreach ($wp->select("SELECT * FROM {$t('wc_order_addresses')} WHERE order_id = ?", [$o['id']]) as $a) {
                $addresses[$a['address_type']] = array_filter([
                    'first_name' => $a['first_name'], 'last_name' => $a['last_name'], 'address_1' => $a['address_1'], 'address_2' => $a['address_2'],
                    'city' => $a['city'], 'state' => $a['state'], 'postcode' => $a['postcode'], 'country' => $a['country'], 'phone' => $a['phone'], 'email' => $a['email'],
                ], static fn ($v) => $v !== null && $v !== '');
            }
            $items = $wp->select("SELECT * FROM {$t('woocommerce_order_items')} WHERE order_id = ? ORDER BY order_item_id", [$o['id']]);
            $itemMeta = function (int $itemId) use ($wp, $t): array {
                $m = [];
                foreach ($wp->select("SELECT meta_key, meta_value FROM {$t('woocommerce_order_itemmeta')} WHERE order_item_id = ?", [$itemId]) as $r) {
                    $m[$r['meta_key']] ??= $r['meta_value'];
                }
                return $m;
            };
            $c = static fn ($v) => (int) round((float) $v * 100);
            $lines = [];
            $shippingByVendor = [];
            $coupons = [];
            foreach ($items as $it) {
                $m = $itemMeta((int) $it['order_item_id']);
                if ($it['order_item_type'] === 'line_item') {
                    $productId = $this->map['product'][(int) ($m['_product_id'] ?? 0)] ?? null;
                    $vendorId = $productId ? (int) $this->db->scalar('SELECT vendor_id FROM products WHERE id = ?', [$productId]) : ($this->map['vendor'][(int) ($m['seller_id'] ?? 0)] ?? null);
                    $label = implode(' / ', array_filter([$m['pa_length'] ?? null ? $this->valueName('length', (string) $m['pa_length']) : null, $m['pa_color'] ?? null ? $this->valueName('color', (string) $m['pa_color']) : null, $m['color'] ?? null]));
                    $lines[] = [
                        'vendor_id' => $vendorId, 'product_id' => $productId,
                        'variant_id' => $this->db->scalar('SELECT id FROM product_variants WHERE legacy_wp_id = ?', [(int) ($m['_variation_id'] ?? 0) ?: (int) ($m['_product_id'] ?? 0)]),
                        'name' => preg_replace('/\s+-\s+[^-]+$/u', '', (string) $it['order_item_name']) ?: (string) $it['order_item_name'],
                        'label' => $label ?: null, 'qty' => max(1, (int) ($m['_qty'] ?? 1)),
                        'subtotal' => $c($m['_line_subtotal'] ?? 0), 'total' => $c($m['_line_total'] ?? 0), 'tax' => $c($m['_line_tax'] ?? 0),
                        'rate' => is_numeric($m['_dokan_commission_rate'] ?? null) ? (float) $m['_dokan_commission_rate'] : 15.0,
                    ];
                } elseif ($it['order_item_type'] === 'shipping') {
                    $vid = $this->map['vendor'][(int) ($m['seller_id'] ?? 0)] ?? null;
                    $shippingByVendor[$vid ?? 0] = ['cents' => ($shippingByVendor[$vid ?? 0]['cents'] ?? 0) + $c($m['cost'] ?? 0), 'title' => (string) $it['order_item_name']];
                } elseif ($it['order_item_type'] === 'coupon') {
                    $coupons[] = (string) $it['order_item_name'];
                }
            }
            $lines = array_values(array_filter($lines, static fn ($l) => $l['vendor_id']));
            if (!$lines) {
                continue;
            }
            $refunded = (int) round(abs((float) $wp->scalar("SELECT COALESCE(SUM(total_amount), 0) FROM {$t('wc_orders')} WHERE type = 'shop_order_refund' AND parent_order_id = ?", [$o['id']])) * 100);
            $userId = $this->map['user'][(int) $o['customer_id']] ?? null;
            $number = (string) $o['id'];

            $orderId = $this->content->upsert('orders', [
                'number' => $number,
                'access_key' => (string) ($this->db->scalar('SELECT access_key FROM orders WHERE legacy_wp_id = ?', [$o['id']]) ?: bin2hex(random_bytes(20))),
                'user_id' => $userId,
                'email' => strtolower((string) ($o['billing_email'] ?: ($addresses['billing']['email'] ?? 'unknown@invalid'))),
                'status' => $statusMap[$o['status']],
                'currency' => (string) ($o['currency'] ?: 'USD'),
                'subtotal_cents' => array_sum(array_column($lines, 'subtotal')),
                'discount_cents' => $c($op['discount_total_amount'] ?? 0),
                'shipping_cents' => $c($op['shipping_total_amount'] ?? 0),
                'tax_cents' => $c($o['tax_amount']),
                'total_cents' => $c($o['total_amount']),
                'refunded_cents' => $refunded,
                'billing_address' => json_encode($addresses['billing'] ?? [], JSON_UNESCAPED_UNICODE),
                'shipping_address' => json_encode($addresses['shipping'] ?? ($addresses['billing'] ?? []), JSON_UNESCAPED_UNICODE),
                'customer_note' => $o['customer_note'] ?: null,
                'coupon_codes' => $coupons ? json_encode($coupons) : null,
                'payment_method' => 'stripe',
                'payment_method_title' => $o['payment_method_title'] ?: null,
                'stripe_payment_intent_id' => ($meta['_stripe_intent_id'] ?? '') ?: null,
                'stripe_charge_id' => $o['transaction_id'] ?: null,
                'paid_at' => $op['date_paid_gmt'] ?? null,
                'completed_at' => $op['date_completed_gmt'] ?? null,
                'stock_reduced' => (int) ($op['order_stock_reduced'] ?? 0),
                'ip' => $o['ip_address'] ?? null,
                'user_agent' => mb_substr_safe((string) ($o['user_agent'] ?? ''), 255) ?: null,
                'legacy_wp_id' => (int) $o['id'],
                'created_at' => $o['date_created_gmt'],
            ], ['legacy_wp_id' => (int) $o['id']]);

            // Rebuild the per-vendor split for this order.
            $this->db->delete('vendor_orders', ['order_id' => $orderId]);
            $byVendor = [];
            foreach ($lines as $l) {
                $byVendor[$l['vendor_id']][] = $l;
            }
            foreach ($byVendor as $vendorId => $vLines) {
                $legacyVendor = (int) array_search($vendorId, $this->map['vendor'], true);
                $net = $wp->scalar("SELECT net_amount FROM {$t('dokan_orders')} WHERE order_id = ? AND seller_id = ?", [$o['id'], $legacyVendor]);
                $ship = $shippingByVendor[$legacyVendor] ?? (count($byVendor) === 1 ? ($shippingByVendor[0] ?? null) ?? reset($shippingByVendor) : null) ?: ['cents' => 0, 'title' => null];
                $subtotal = array_sum(array_column($vLines, 'subtotal'));
                $afterDiscount = array_sum(array_column($vLines, 'total'));
                $tax = array_sum(array_column($vLines, 'tax'));
                $rate = $vLines[0]['rate'];
                $commission = (int) round($afterDiscount * $rate / 100);
                $voStatus = $statusMap[$o['status']] === 'pending' ? 'pending' : $statusMap[$o['status']];
                $voId = $this->db->insert('vendor_orders', [
                    'order_id' => $orderId, 'vendor_id' => $vendorId, 'status' => $voStatus,
                    'subtotal_cents' => $subtotal, 'discount_cents' => $subtotal - $afterDiscount,
                    'shipping_cents' => (int) $ship['cents'], 'shipping_method' => $ship['title'], 'tax_cents' => $tax,
                    'total_cents' => $afterDiscount + (int) $ship['cents'] + $tax,
                    'commission_rate' => $rate, 'commission_cents' => $commission,
                    'vendor_earning_cents' => $net !== null ? $c($net) : $afterDiscount - $commission + (int) $ship['cents'] + $tax,
                    'completed_at' => $statusMap[$o['status']] === 'completed' ? ($op['date_completed_gmt'] ?? null) : null,
                    'created_at' => $o['date_created_gmt'],
                ]);
                foreach ($vLines as $l) {
                    $this->db->insert('order_items', [
                        'order_id' => $orderId, 'vendor_order_id' => $voId, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'],
                        'name' => mb_substr_safe($l['name'], 255), 'variant_label' => $l['label'], 'quantity' => $l['qty'],
                        'unit_price_cents' => intdiv($l['subtotal'], max(1, $l['qty'])), 'subtotal_cents' => $l['subtotal'],
                        'discount_cents' => $l['subtotal'] - $l['total'], 'tax_cents' => $l['tax'], 'total_cents' => $l['total'],
                    ]);
                }
            }

            $this->db->delete('refunds', ['order_id' => $orderId]);
            foreach ($wp->select("SELECT * FROM {$t('wc_orders')} WHERE type = 'shop_order_refund' AND parent_order_id = ?", [$o['id']]) as $r) {
                $rm = [];
                foreach ($wp->select("SELECT meta_key, meta_value FROM {$t('wc_orders_meta')} WHERE order_id = ?", [$r['id']]) as $row) {
                    $rm[$row['meta_key']] ??= $row['meta_value'];
                }
                $this->db->insert('refunds', [
                    'order_id' => $orderId, 'amount_cents' => abs($c($r['total_amount'])), 'reason' => ($rm['_refund_reason'] ?? '') ?: null,
                    'stripe_refund_id' => ($rm['_stripe_refund_id'] ?? '') ?: null, 'status' => 'succeeded', 'legacy_wp_id' => (int) $r['id'], 'created_at' => $r['date_created_gmt'],
                ]);
            }
            $count++;
        }
        ($this->log)("{$count} orders");
    }

    /* ------------------------------------------------------------------ vendor balances */

    /**
     * Dokan's vendor balance and withdrawals. Only orders Dokan recorded count
     * (others were settled outside Dokan); like Dokan, an order's earning is
     * withdrawable only when it is completed. Safe to re-run.
     */
    public function importBalances(): void
    {
        $this->loadMaps();
        $wp = $this->wp->db();
        $t = fn (string $name) => $this->wp->table($name);
        $this->db->query("DELETE FROM vendor_ledger WHERE reference LIKE 'dokan:%'");

        $voByOrder = [];
        foreach ($this->db->select('SELECT vo.id, vo.vendor_id, o.legacy_wp_id, o.number FROM vendor_orders vo JOIN orders o ON o.id = vo.order_id WHERE o.legacy_wp_id IS NOT NULL') as $r) {
            $voByOrder[(int) $r['legacy_wp_id'] . ':' . (int) $r['vendor_id']] = $r;
        }
        $count = 0;
        foreach ($wp->select("SELECT * FROM {$t('dokan_vendor_balance')} WHERE trn_type = 'dokan_orders' AND status = 'wc-completed' ORDER BY id") as $b) {
            $vendorId = $this->map['vendor'][(int) $b['vendor_id']] ?? null;
            if ($vendorId === null) {
                continue;
            }
            $vo = $voByOrder[(int) $b['trn_id'] . ':' . $vendorId] ?? null;
            $amount = self::cents($b['debit']) - self::cents($b['credit']);
            $this->db->insert('vendor_ledger', [
                'vendor_id' => $vendorId,
                'vendor_order_id' => $vo ? (int) $vo['id'] : null,
                'type' => 'earning',
                'amount_cents' => $amount,
                'description' => 'Order #' . ($vo['number'] ?? $b['trn_id']),
                'available_at' => $b['balance_date'],
                'reference' => 'dokan:balance:' . (int) $b['id'],
                'created_at' => $b['trn_date'],
            ]);
            $count++;
        }

        $withdrawals = 0;
        $exists = (bool) $wp->first("SHOW TABLES LIKE '" . $t('dokan_withdraw') . "'");
        foreach ($exists ? $wp->select("SELECT * FROM {$t('dokan_withdraw')} ORDER BY id") : [] as $w) {
            $vendorId = $this->map['vendor'][(int) $w['user_id']] ?? null;
            if ($vendorId === null) {
                continue;
            }
            $status = match ((int) $w['status']) { 1 => 'paid', 2 => 'cancelled', default => 'pending' };
            $amount = self::cents($w['amount']);
            $requestId = $this->content->upsert('payout_requests', [
                'vendor_id' => $vendorId,
                'amount_cents' => $amount,
                'method' => $w['method'] === 'paypal' ? 'paypal' : 'bank',
                'status' => $status,
                'vendor_note' => ($w['note'] ?? '') !== '' ? mb_substr_safe((string) $w['note'], 500) : null,
                'processed_at' => $status !== 'pending' ? $w['date'] : null,
                'legacy_wp_id' => (int) $w['id'],
                'created_at' => $w['date'],
            ], ['legacy_wp_id' => (int) $w['id']]);
            if ($status === 'paid') {
                $this->db->insert('vendor_ledger', [
                    'vendor_id' => $vendorId, 'type' => 'payout', 'amount_cents' => -$amount,
                    'description' => 'Payout via ' . ($w['method'] === 'paypal' ? 'PayPal' : 'Bank transfer'),
                    'available_at' => $w['date'], 'reference' => 'dokan:withdraw:' . (int) $w['id'], 'created_at' => $w['date'],
                ]);
            }
            $withdrawals++;
        }
        ($this->log)("Vendor balance rows: {$count}, withdrawals: {$withdrawals}");
    }

    private function valueName(string $attribute, string $legacySlug): ?string
    {
        $termId = (int) $this->wp->db()->scalar(
            "SELECT t.term_id FROM {$this->wp->table('terms')} t JOIN {$this->wp->table('term_taxonomy')} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = ? AND t.slug = ?",
            ['pa_' . $attribute, $legacySlug],
        );
        $id = $this->map['value'][$termId] ?? null;
        return $id ? (string) $this->db->scalar('SELECT name FROM attribute_values WHERE id = ?', [$id]) : null;
    }

    /* ---------------------------------------------------------------- helpers */

    private function cleanText(string $html): string
    {
        // Emoji lost in the old database show as "????"; drop them.
        $html = (string) preg_replace('/\?{3,}\s*/', '', $html);
        return trim($html) === '' ? '' : $this->content->convertContent($html);
    }

    private function upsertAttribute(string $slug, string $name, string $display, int $order): int
    {
        return $this->content->upsert('attributes', ['slug' => $slug, 'name' => $name, 'display' => $display, 'sort_order' => $order], ['slug' => $slug]);
    }

    private function upsertValue(int $attributeId, string $name, ?int $legacy): int
    {
        $isLength = (bool) $this->db->scalar("SELECT 1 FROM attributes WHERE id = ? AND slug = 'length'", [$attributeId]);
        $inches = $isLength && preg_match('/(\d+)/', $name, $m) ? (int) $m[1] : null;
        $slug = $inches !== null ? $inches . '-inch' : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $row = [
            'attribute_id' => $attributeId,
            'slug' => $slug,
            'name' => $inches !== null ? $inches . '"' : $name,
            'swatch' => self::COLOR_SWATCHES[strtolower($name)] ?? null,
            'sort_order' => $inches ?? 0,
        ];
        if ($legacy !== null) {
            $row['legacy_wp_id'] = $legacy;
        }
        return $this->content->upsert('attribute_values', $row, ['attribute_id' => $attributeId, 'slug' => $slug]);
    }

    /** Media id for a WordPress attachment id (also resolves duplicate attachments of one file). */
    private function mediaId(int $wpId): ?int
    {
        if ($wpId <= 0) {
            return null;
        }
        if (!array_key_exists($wpId, $this->map['media'])) {
            $file = $this->wp->db()->scalar("SELECT meta_value FROM {$this->wp->table('postmeta')} WHERE post_id = ? AND meta_key = '_wp_attached_file'", [$wpId]);
            $id = $file ? $this->db->scalar('SELECT id FROM media WHERE path = ?', [$file]) : null;
            $this->map['media'][$wpId] = $id !== null ? (int) $id : null;
        }
        return $this->map['media'][$wpId];
    }

    /** WooCommerce billing_/shipping_ user meta as an address array (null when empty). */
    private function wcAddress(array $meta, string $type): ?string
    {
        $fields = ['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'] + ($type === 'billing' ? [8 => 'phone'] : []);
        $address = [];
        foreach ($fields as $f) {
            $value = trim((string) ($meta[$type . '_' . $f] ?? ''));
            if ($value !== '') {
                $address[$f] = mb_substr_safe($value, 200);
            }
        }
        return isset($address['address_1']) ? json_encode($address, JSON_UNESCAPED_UNICODE) : null;
    }

    private function userMeta(int $userId): array
    {
        $meta = [];
        foreach ($this->wp->db()->select("SELECT meta_key, meta_value FROM {$this->wp->table('usermeta')} WHERE user_id = ?", [$userId]) as $row) {
            $meta[$row['meta_key']] ??= WordPressSource::maybeUnserialize((string) $row['meta_value']);
        }
        return $meta;
    }

    private function loadMaps(): void
    {
        $pairs = ['user' => 'users', 'vendor' => 'vendors', 'media' => 'media', 'category' => 'product_categories', 'tag' => 'product_tags', 'value' => 'attribute_values', 'product' => 'products'];
        foreach ($pairs as $key => $table) {
            $this->map[$key] = [];
            foreach ($this->db->select("SELECT id, legacy_wp_id FROM {$table} WHERE legacy_wp_id IS NOT NULL") as $row) {
                $this->map[$key][(int) $row['legacy_wp_id']] = (int) $row['id'];
            }
        }
        foreach ($this->db->select('SELECT id, slug FROM attributes') as $row) {
            $this->attributeIds[$row['slug']] = (int) $row['id'];
        }
    }

    private static function cents(mixed $price): int
    {
        return (int) round(((float) str_replace(',', '.', (string) $price)) * 100);
    }

    private static function date(mixed $timestamp): ?string
    {
        return is_numeric($timestamp) && (int) $timestamp > 0 ? gmdate('Y-m-d H:i:s', (int) $timestamp) : null;
    }
}
