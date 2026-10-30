<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * XML sitemaps with the same file names as the old Rank Math setup
 * (/sitemap_index.xml, /post-sitemap.xml, …), plus vendor stores. Private
 * pages (cart, checkout, account, dashboards) and noindex items are left out.
 * Every URL is built from the current host, never a hard-coded domain.
 */
final class Sitemap
{
    public const TYPES = ['post', 'page', 'product', 'category', 'product_cat', 'store'];
    private const PRIVATE_TEMPLATES = ['cart', 'checkout', 'account', 'thank-you', 'vendor-dashboard'];
    private const VISIBLE = "p.status = 'published' AND v.status = 'approved' AND v.vacation_mode = 0";

    public function __construct(private Database $db)
    {
    }

    /** @return array<int, array{loc:string, lastmod:?string, images?:array<int,array{loc:string,title?:string}>}> */
    public function urls(string $type): array
    {
        $base = app()->url()->base();
        $abs = static fn (string $path) => $base . '/' . ltrim($path, '/');
        $date = static fn (?string $d) => $d ? gmdate('c', strtotime($d)) : null;
        $images = app()->images();
        $noindex = static fn (?string $robots) => $robots !== null && stripos($robots, 'noindex') !== false;

        switch ($type) {
            case 'page':
                $rows = $this->db->select("SELECT slug, template, seo_robots, updated_at FROM pages WHERE status = 'published' ORDER BY slug = 'home' DESC, slug");
                $out = [];
                foreach ($rows as $r) {
                    if (in_array($r['template'], self::PRIVATE_TEMPLATES, true) || $noindex($r['seo_robots'])) {
                        continue;
                    }
                    $out[] = ['loc' => $abs($r['slug'] === 'home' ? '/' : '/' . $r['slug'] . '/'), 'lastmod' => $date($r['updated_at'])];
                }
                return $out;

            case 'post':
                $rows = $this->db->select("SELECT slug, title, featured_media_id, seo_robots, updated_at FROM posts WHERE status = 'published' ORDER BY published_at DESC");
                $images->prime(array_filter(array_map('intval', array_column($rows, 'featured_media_id'))));
                return array_values(array_map(static fn ($r) => ['loc' => $abs('/' . $r['slug'] . '/'), 'lastmod' => $date($r['updated_at']),
                    'images' => $r['featured_media_id'] ? [['loc' => self::absolute($images->url((int) $r['featured_media_id'])), 'title' => $r['title']]] : []],
                    array_filter($rows, static fn ($r) => !$noindex($r['seo_robots']))));

            case 'product':
                $rows = $this->db->select('SELECT p.id, p.slug, p.name, p.featured_media_id, p.updated_at FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE ' . self::VISIBLE . ' ORDER BY p.published_at DESC');
                $gallery = [];
                foreach ($rows ? $this->db->select('SELECT product_id, media_id FROM product_images WHERE product_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ') ORDER BY sort_order') : [] as $g) {
                    $gallery[(int) $g['product_id']][] = (int) $g['media_id'];
                }
                $images->prime(array_merge(array_filter(array_map('intval', array_column($rows, 'featured_media_id'))), ...array_values($gallery ?: [[]])));
                return array_map(static function ($r) use ($abs, $date, $images, $gallery) {
                    $ids = array_values(array_unique(array_filter(array_merge([(int) $r['featured_media_id']], $gallery[(int) $r['id']] ?? []))));
                    return ['loc' => $abs('/product/' . $r['slug'] . '/'), 'lastmod' => $date($r['updated_at']),
                        'images' => array_map(static fn ($id) => ['loc' => self::absolute($images->url($id)), 'title' => $r['name']], $ids)];
                }, $rows);

            case 'category':
                return array_map(static fn ($r) => ['loc' => $abs('/category/' . $r['slug'] . '/'), 'lastmod' => $date($r['lastmod'])],
                    $this->db->select("SELECT c.slug, MAX(p.updated_at) lastmod FROM post_categories c JOIN post_category pc ON pc.category_id = c.id JOIN posts p ON p.id = pc.post_id AND p.status = 'published' GROUP BY c.id ORDER BY c.slug"));

            case 'product_cat':
                // Only categories that have something to show (a category page counts its sub-categories' products).
                return array_map(static fn ($r) => ['loc' => $abs('/collection/' . $r['path'] . '/'), 'lastmod' => $date($r['lastmod'])],
                    $this->db->select(
                        'SELECT c.path, MAX(p.updated_at) lastmod FROM product_categories c
                         JOIN product_categories d ON d.id = c.id OR d.path LIKE CONCAT(c.path, "/%")
                         JOIN product_category pc ON pc.category_id = d.id JOIN products p ON p.id = pc.product_id JOIN vendors v ON v.id = p.vendor_id
                         WHERE ' . self::VISIBLE . ' GROUP BY c.id ORDER BY c.path'
                    ));

            case 'store':
                return array_map(static fn ($r) => ['loc' => $abs('/store/' . $r['slug'] . '/'), 'lastmod' => $date($r['lastmod'])],
                    $this->db->select('SELECT v.slug, GREATEST(v.updated_at, COALESCE(MAX(p.updated_at), v.updated_at)) lastmod FROM vendors v LEFT JOIN products p ON p.vendor_id = v.id AND p.status = "published" WHERE v.status = "approved" GROUP BY v.id ORDER BY v.store_name'));
        }
        throw new \InvalidArgumentException("Unknown sitemap type {$type}");
    }

    public function index(): string
    {
        $base = app()->url()->base();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::TYPES as $type) {
            $urls = $this->urls($type);
            if (!$urls) {
                continue;
            }
            $last = max(array_map(static fn ($u) => (string) ($u['lastmod'] ?? ''), $urls));
            $xml .= "\t<sitemap>\n\t\t<loc>" . self::x($base . '/' . $type . '-sitemap.xml') . "</loc>\n" . ($last !== '' ? "\t\t<lastmod>" . self::x($last) . "</lastmod>\n" : '') . "\t</sitemap>\n";
        }
        return $xml . '</sitemapindex>' . "\n";
    }

    public function render(string $type): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($this->urls($type) as $u) {
            $xml .= "\t<url>\n\t\t<loc>" . self::x($u['loc']) . "</loc>\n";
            if (!empty($u['lastmod'])) {
                $xml .= "\t\t<lastmod>" . self::x($u['lastmod']) . "</lastmod>\n";
            }
            foreach ($u['images'] ?? [] as $img) {
                if ($img['loc'] !== '') {
                    $xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . self::x($img['loc']) . "</image:loc>\n\t\t</image:image>\n";
                }
            }
            $xml .= "\t</url>\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    public static function absolute(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#', $url)) {
            return $url;
        }
        // upload_url() already carries the sub-directory, so only add scheme + host.
        $base = app()->url()->base();
        $origin = substr($base, 0, strlen($base) - strlen(app()->request()->basePath()));
        return $origin . $url;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
