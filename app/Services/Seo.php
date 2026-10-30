<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Head metadata for a page: title, description, canonical, robots, Open Graph
 * and JSON-LD. Defaults follow the old Rank Math patterns ("%title% - %sitename%").
 */
final class Seo
{
    public string $title = '';
    public string $description = '';
    public ?string $canonical = null;
    public ?string $robots = null;
    public ?string $image = null;
    public string $type = 'website';
    /** @var array<int,array> */
    public array $schema = [];

    public static function make(string $title, ?string $customTitle = null, ?string $description = null): self
    {
        $seo = new self();
        $site = (string) config('app.name');
        $seo->title = $customTitle !== null && $customTitle !== '' ? $customTitle : ($title !== '' ? "{$title} - {$site}" : $site);
        $seo->description = self::clip((string) $description, 160);
        $seo->canonical = app()->url()->current(false);
        return $seo;
    }

    public function robots(?string $robots): self
    {
        $this->robots = $robots;
        return $this;
    }

    public function image(?string $url): self
    {
        $this->image = $url ? self::absolute($url) : null;
        return $this;
    }

    /** Absolute URL for a site-relative one (which already includes any sub-directory). */
    public static function absolute(string $url): string
    {
        return Sitemap::absolute($url);
    }

    /**
     * BreadcrumbList JSON-LD. The current page is the last item.
     * @param array<int, array{0:string,1:string}> $trail [name, path] pairs, starting after Home
     */
    public function breadcrumbs(array $trail): self
    {
        $items = [['Home', '/']];
        foreach ($trail as $crumb) {
            $items[] = $crumb;
        }
        $list = [];
        foreach ($items as $i => [$name, $path]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'item' => app()->url()->to($path)];
        }
        return $this->schema(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list]);
    }

    public function type(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function schema(array $schema): self
    {
        $this->schema[] = $schema;
        return $this;
    }

    public static function clip(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if (str_length($text) <= $length) {
            return $text;
        }
        $cut = mb_substr_safe($text, $length - 1);
        $space = strrpos($cut, ' ');
        return rtrim($space ? substr($cut, 0, $space) : $cut, ' ,.;:-') . '…';
    }
}
