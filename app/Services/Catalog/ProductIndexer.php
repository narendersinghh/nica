<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;

/**
 * Keeps the denormalized listing columns on products (prices, stock, sale flag)
 * and review aggregates on products/vendors in sync. Call after any change to
 * variants or reviews.
 */
final class ProductIndexer
{
    public function __construct(private Database $db)
    {
    }

    public function refresh(int $productId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $variants = $this->db->select('SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1', [$productId]);

        $prices = $regulars = [];
        $inStock = false;
        $onSale = false;
        foreach ($variants as $v) {
            $price = self::effectivePrice($v, $now);
            $prices[] = $price;
            $regulars[] = (int) $v['regular_price_cents'];
            $onSale = $onSale || $price < (int) $v['regular_price_cents'];
            $inStock = $inStock || self::isPurchasable($v);
        }

        $this->db->update('products', [
            'price_min_cents' => $prices ? min($prices) : 0,
            'price_max_cents' => $prices ? max($prices) : 0,
            'regular_min_cents' => $regulars ? min($regulars) : 0,
            'regular_max_cents' => $regulars ? max($regulars) : 0,
            'on_sale' => $onSale ? 1 : 0,
            'in_stock' => $inStock ? 1 : 0,
        ], ['id' => $productId]);
    }

    public function refreshRatings(int $productId): void
    {
        $row = $this->db->first("SELECT COUNT(*) c, COALESCE(AVG(rating), 0) a FROM reviews WHERE product_id = ? AND status = 'approved'", [$productId]);
        $this->db->update('products', ['rating_count' => (int) $row['c'], 'rating_avg' => round((float) $row['a'], 2)], ['id' => $productId]);

        $vendorId = (int) $this->db->scalar('SELECT vendor_id FROM products WHERE id = ?', [$productId]);
        $v = $this->db->first("SELECT COUNT(*) c, COALESCE(AVG(rating), 0) a FROM reviews WHERE vendor_id = ? AND status = 'approved'", [$vendorId]);
        $this->db->update('vendors', ['rating_count' => (int) $v['c'], 'rating_avg' => round((float) $v['a'], 2)], ['id' => $vendorId]);
    }

    /** Sale price applies only inside its (optional) schedule. */
    public static function effectivePrice(array $variant, ?string $now = null): int
    {
        $now ??= gmdate('Y-m-d H:i:s');
        $regular = (int) $variant['regular_price_cents'];
        $sale = $variant['sale_price_cents'];
        if ($sale === null || (int) $sale >= $regular) {
            return $regular;
        }
        if (($variant['sale_starts_at'] && $variant['sale_starts_at'] > $now) || ($variant['sale_ends_at'] && $variant['sale_ends_at'] < $now)) {
            return $regular;
        }
        return (int) $sale;
    }

    public static function isPurchasable(array $variant): bool
    {
        if (!(int) $variant['is_active'] || $variant['stock_status'] === 'outofstock') {
            return false;
        }
        return !(int) $variant['manage_stock'] || (int) $variant['stock_qty'] > 0 || $variant['stock_status'] === 'onbackorder';
    }
}
