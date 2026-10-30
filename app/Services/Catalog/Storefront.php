<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Catalog data used by content pages (homepage tiles, product cards, reviews,
 * products inside blog posts), shaped for the templates.
 */
final class Storefront
{
    /** Top-level product categories for the homepage / shop tiles. */
    public static function featuredCategories(int $limit = 12): array
    {
        return array_map(static fn (array $c) => [
            'name' => $c['name'],
            'url' => link_to('/collection/' . $c['path'] . '/'),
            'image' => $c['image_media_id'] ? (int) $c['image_media_id'] : null,
        ], array_slice(app()->catalog()->topCategories(true), 0, $limit));
    }

    /** Texture tags (Wavy, Straight, ...) for "Shop by hair type". */
    public static function hairTypes(int $limit = 4): array
    {
        return array_map(static fn (array $t) => [
            'name' => $t['name'],
            'subtitle' => (string) $t['subtitle'],
            'url' => link_to('/hair-texture/' . $t['slug'] . '/'),
            'image' => $t['image_media_id'] ? (int) $t['image_media_id'] : null,
        ], app()->catalog()->hairTypes($limit));
    }

    /** "Most-loved products" cards. */
    public static function featuredProducts(int $limit = 4): array
    {
        $rows = app()->catalog()->featured($limit);
        app()->images()->prime(array_column($rows, 'featured_media_id'));
        return $rows;
    }

    /** Approved reviews shown as testimonials. */
    public static function topReviews(int $limit = 3): array
    {
        return app()->catalog()->topReviews($limit);
    }

    /**
     * Products for the related-products block inside blog posts.
     * @param int[] $legacyIds WordPress product IDs chosen in the old post (empty: pick by relevance)
     */
    public static function relatedForPost(array $post, array $legacyIds = [], int $limit = 3): array
    {
        $catalog = app()->catalog();
        if ($legacyIds !== []) {
            return array_slice($catalog->cards($catalog->productIdsByLegacy($legacyIds)), 0, $limit);
        }
        $result = $catalog->products(['search' => $post['title'], 'sort' => 'relevance'], 1, $limit);
        return $result['items'] ?: $catalog->featured($limit);
    }
}
