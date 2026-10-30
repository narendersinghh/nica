<?php

declare(strict_types=1);

namespace App\Models;

final class Page
{
    public static function findPublished(string $slug): ?array
    {
        $page = app()->db()->first("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
        if ($page !== null) {
            $page['data'] = json_decode((string) ($page['data'] ?? ''), true) ?: [];
        }
        return $page;
    }
}
