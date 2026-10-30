<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Database;

/** Read-only access to the copy of the old WordPress database. */
final class WordPressSource
{
    private array $optionCache = [];

    public function __construct(private Database $db, private string $prefix = 'wp_')
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix.');
        }
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $this->optionCache)) {
            $value = $this->db->scalar("SELECT option_value FROM {$this->table('options')} WHERE option_name = ?", [$name]);
            $this->optionCache[$name] = $value === null ? null : self::maybeUnserialize((string) $value);
        }
        return $this->optionCache[$name] ?? $default;
    }

    /** Site URL of the old install, e.g. https://example.com (no trailing slash). */
    public function siteUrl(): string
    {
        return rtrim((string) $this->option('siteurl', ''), '/');
    }

    /** @return array<string,mixed> meta_key => value (first value wins) */
    public function postMeta(int $postId): array
    {
        $meta = [];
        foreach ($this->db->select("SELECT meta_key, meta_value FROM {$this->table('postmeta')} WHERE post_id = ? ORDER BY meta_id", [$postId]) as $row) {
            $meta[$row['meta_key']] ??= self::maybeUnserialize((string) $row['meta_value']);
        }
        return $meta;
    }

    public function posts(string $type, array $statuses = ['publish']): array
    {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        return $this->db->select(
            "SELECT * FROM {$this->table('posts')} WHERE post_type = ? AND post_status IN ({$in}) ORDER BY ID",
            [$type, ...$statuses],
        );
    }

    /** @return array<int,array{term_id:int,name:string,slug:string,description:string,parent:int,count:int}> */
    public function terms(string $taxonomy): array
    {
        return $this->db->select(
            "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count, tt.term_taxonomy_id
             FROM {$this->table('terms')} t JOIN {$this->table('term_taxonomy')} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = ? ORDER BY t.term_id",
            [$taxonomy],
        );
    }

    public function termMeta(int $termId): array
    {
        $meta = [];
        foreach ($this->db->select("SELECT meta_key, meta_value FROM {$this->table('termmeta')} WHERE term_id = ?", [$termId]) as $row) {
            $meta[$row['meta_key']] ??= self::maybeUnserialize((string) $row['meta_value']);
        }
        return $meta;
    }

    /** @return int[] term ids of $taxonomy attached to the object */
    public function objectTermIds(int $objectId, string $taxonomy): array
    {
        return array_map('intval', array_column($this->db->select(
            "SELECT tt.term_id FROM {$this->table('term_relationships')} tr
             JOIN {$this->table('term_taxonomy')} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = ? AND tt.taxonomy = ? ORDER BY tr.term_order",
            [$objectId, $taxonomy],
        ), 'term_id'));
    }

    /** PHP-serialized values are decoded without ever instantiating objects. */
    public static function maybeUnserialize(string $value): mixed
    {
        if ($value === '' || !preg_match('/^(a|s|i|d|b|O|N):/', $value)) {
            return $value;
        }
        if ($value === 'b:0;') {
            return false;
        }
        $decoded = @unserialize($value, ['allowed_classes' => false]);
        return $decoded === false ? $value : $decoded;
    }
}
