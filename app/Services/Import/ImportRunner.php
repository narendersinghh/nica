<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Database;
use App\Core\Env;
use App\Services\Cache\PageCache;

/**
 * Runs WordPress import steps against the configured copy of the WordPress
 * database (WP_DB_*). Shared by "bin/console import:wp" and the setup wizard,
 * which runs one step per request so no step hits a hosting time limit.
 */
final class ImportRunner
{
    public const CONTENT_STEPS = ['media', 'categories', 'posts', 'pages', 'faqs', 'redirects'];
    public const CATALOG_STEPS = ['users', 'vendors', 'taxonomies', 'products', 'reviews', 'quiz', 'coupons', 'orders', 'balances'];
    public const LABELS = [
        'media' => 'Image library', 'categories' => 'Blog categories', 'posts' => 'Blog posts', 'pages' => 'Pages', 'faqs' => 'FAQs',
        'redirects' => 'Redirects', 'users' => 'Customers and accounts', 'vendors' => 'Vendor stores', 'taxonomies' => 'Product categories',
        'products' => 'Products', 'reviews' => 'Reviews', 'quiz' => 'Find-match quiz', 'coupons' => 'Coupons', 'orders' => 'Orders', 'balances' => 'Vendor balances',
    ];

    /** The WordPress copy, with its own login when WP_DB_USERNAME is set (as on Hostinger). */
    public static function source(?array $override = null): WordPressSource
    {
        $cfg = $override ?? array_filter([
            'host' => Env::get('WP_DB_HOST'),
            'port' => Env::get('WP_DB_PORT'),
            'username' => Env::get('WP_DB_USERNAME'),
            'password' => Env::get('WP_DB_PASSWORD'),
        ], static fn ($v) => $v !== null && $v !== '');
        $db = new Database(array_merge((array) config('database'), $cfg, [
            'database' => (string) ($override['database'] ?? Env::get('WP_DB_DATABASE', 'nica_wp')),
            'collation' => 'utf8mb4_unicode_520_ci',
        ]));
        return new WordPressSource($db, (string) ($override['prefix'] ?? Env::get('WP_DB_PREFIX', 'wp_')));
    }

    /**
     * @param string[] $steps any of CONTENT_STEPS / CATALOG_STEPS, run in the official order
     * @param bool $download fetch media files from the old site (GET only); false = files were copied by hand
     */
    public static function run(array $steps, bool $download, callable $out): void
    {
        $app = app();
        $source = self::source();
        $importer = new WordPressImporter($source, $app->db(), $app->path('public/uploads'), $app->path('database/content/pages'), $out);
        $importer->run(array_values(array_intersect(self::CONTENT_STEPS, $steps)), $download);
        (new CatalogImporter($source, $app->db(), $importer, $app->path('database/content'), $out))
            ->run(array_values(array_intersect(self::CATALOG_STEPS, $steps)));
        PageCache::flush();
    }
}
