<?php

declare(strict_types=1);

namespace App\Models;

final class Post
{
    private const LISTING = "SELECT p.id, p.slug, p.title, p.excerpt, p.featured_media_id, p.published_at, p.reading_minutes
                             FROM posts p";

    public static function findPublished(string $slug): ?array
    {
        return app()->db()->first(
            "SELECT * FROM posts WHERE slug = ? AND status = 'published' AND published_at <= UTC_TIMESTAMP()",
            [$slug],
        );
    }

    public static function latest(int $limit, ?int $exceptId = null): array
    {
        $posts = app()->db()->select(
            self::LISTING . " WHERE p.status = 'published' AND p.published_at <= UTC_TIMESTAMP()"
            . ($exceptId ? ' AND p.id <> ' . (int) $exceptId : '')
            . ' ORDER BY p.published_at DESC LIMIT ' . max(1, $limit),
        );
        self::primeImages($posts);
        return $posts;
    }

    /** @return array{items:array,total:int,page:int,pages:int} */
    public static function paginate(int $page, int $perPage, ?int $categoryId = null): array
    {
        $where = "p.status = 'published' AND p.published_at <= UTC_TIMESTAMP()";
        $params = [];
        if ($categoryId !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM post_category pc WHERE pc.post_id = p.id AND pc.category_id = ?)';
            $params[] = $categoryId;
        }
        $db = app()->db();
        $total = (int) $db->scalar("SELECT COUNT(*) FROM posts p WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, $page);
        $items = $page > $pages ? [] : $db->select(
            self::LISTING . " WHERE {$where} ORDER BY p.published_at DESC LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params,
        );
        self::primeImages($items);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function categories(int $postId): array
    {
        return app()->db()->select(
            'SELECT c.id, c.slug, c.name FROM post_categories c JOIN post_category pc ON pc.category_id = c.id WHERE pc.post_id = ? ORDER BY c.name',
            [$postId],
        );
    }

    public static function related(array $post, int $limit = 3): array
    {
        $posts = app()->db()->select(
            // EXISTS rather than JOIN + GROUP BY: MariaDB (Hostinger) rejects grouping by p.id while selecting other columns.
            self::LISTING . " WHERE p.status = 'published' AND p.published_at <= UTC_TIMESTAMP() AND p.id <> ?
               AND EXISTS (SELECT 1 FROM post_category pc WHERE pc.post_id = p.id AND pc.category_id IN (SELECT category_id FROM post_category WHERE post_id = ?))
             ORDER BY p.published_at DESC LIMIT " . $limit,
            [$post['id'], $post['id']],
        );
        if (count($posts) < $limit) {
            $have = array_column($posts, 'id');
            foreach (self::latest($limit + count($have) + 1, (int) $post['id']) as $extra) {
                if (count($posts) >= $limit) {
                    break;
                }
                if (!in_array($extra['id'], $have, true)) {
                    $posts[] = $extra;
                }
            }
        }
        self::primeImages($posts);
        return $posts;
    }

    public static function url(array $post): string
    {
        return link_to('/' . $post['slug'] . '/');
    }

    private static function primeImages(array $posts): void
    {
        app()->images()->prime(array_column($posts, 'featured_media_id'));
    }
}
