<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Catalog\ProductIndexer;

/**
 * Google Merchant Center feed (RSS 2.0 + g: namespace), one item per active
 * variation grouped by product. Served at /product-feed.xml once switched on
 * in Admin → Settings → Integrations; nothing is sent anywhere by us.
 */
final class ProductFeed
{
    private const VISIBLE = "p.status = 'published' AND v.status = 'approved' AND v.vacation_mode = 0";

    public function __construct(private Database $db)
    {
    }

    public function items(): array
    {
        $rows = $this->db->select(
            "SELECT pv.*, p.slug, p.name, p.short_description, p.description, p.featured_media_id, p.shipping_class, v.store_name,
                    (SELECT c.path FROM product_categories c WHERE c.id = p.primary_category_id) AS category_path,
                    (SELECT GROUP_CONCAT(av.name ORDER BY a.sort_order SEPARATOR ' / ') FROM variant_values vv JOIN attribute_values av ON av.id = vv.attribute_value_id JOIN attributes a ON a.id = vv.attribute_id WHERE vv.variant_id = pv.id) AS variant_label,
                    (SELECT COUNT(*) FROM product_variants x WHERE x.product_id = p.id AND x.is_active = 1) AS siblings
             FROM product_variants pv JOIN products p ON p.id = pv.product_id JOIN vendors v ON v.id = p.vendor_id
             WHERE " . self::VISIBLE . ' AND pv.is_active = 1 AND pv.regular_price_cents > 0 ORDER BY p.id, pv.sort_order, pv.id'
        );
        $images = app()->images();
        $images->prime(array_filter(array_merge(array_map('intval', array_column($rows, 'featured_media_id')), array_map('intval', array_column($rows, 'image_media_id')))));
        $base = app()->url()->base();
        $now = gmdate('Y-m-d H:i:s');
        $googleCategory = (string) Settings::get('integrations.google_merchant.google_category', 'Health & Beauty > Personal Care > Hair Care > Hair Extensions');
        $out = [];
        foreach ($rows as $r) {
            $price = ProductIndexer::effectivePrice($r, $now);
            $item = [
                'id' => $r['sku'] ?: 'nica-' . $r['id'],
                'item_group_id' => (int) $r['siblings'] > 1 ? 'nica-p' . $r['product_id'] : null,
                'title' => mb_substr_safe($r['name'] . ($r['variant_label'] ? ' – ' . $r['variant_label'] : ''), 150),
                'description' => mb_substr_safe(Seo::clip((string) ($r['description'] ?: $r['short_description'] ?: $r['name']), 4900), 5000),
                'link' => $base . '/product/' . $r['slug'] . '/',
                'image_link' => Sitemap::absolute($images->url((int) ($r['image_media_id'] ?: $r['featured_media_id']))),
                'availability' => ProductIndexer::isPurchasable($r) ? ($r['stock_status'] === 'onbackorder' ? 'backorder' : 'in_stock') : 'out_of_stock',
                'price' => number_format((int) $r['regular_price_cents'] / 100, 2, '.', '') . ' USD',
                'sale_price' => $price < (int) $r['regular_price_cents'] ? number_format($price / 100, 2, '.', '') . ' USD' : null,
                'brand' => $r['store_name'],
                'condition' => 'new',
                'google_product_category' => $googleCategory,
                'product_type' => $r['category_path'] ? ucwords(str_replace(['/', '-'], [' > ', ' '], $r['category_path'])) : null,
                'identifier_exists' => 'no',
            ];
            $out[] = $item;
        }
        return $out;
    }

    public function render(): string
    {
        $x = static fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<rss version=\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n<channel>\n"
            . '<title>' . $x(config('app.name')) . "</title>\n<link>" . $x(app()->url()->base() . '/') . "</link>\n<description>" . $x(config('app.name') . ' products') . "</description>\n";
        foreach ($this->items() as $item) {
            $xml .= "<item>\n";
            foreach ($item as $key => $value) {
                if ($value !== null && $value !== '') {
                    $xml .= "\t<g:{$key}>" . $x($value) . "</g:{$key}>\n";
                }
            }
            $xml .= "</item>\n";
        }
        return $xml . "</channel>\n</rss>\n";
    }
}
