<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Database;
use App\Services\Content\AutoParagraph;
use App\Services\Content\HtmlSanitizer;

/**
 * Imports content from the old WordPress database into the new schema.
 * Every step is idempotent (keyed on legacy_wp_id / slug), so it can be re-run.
 * The live site is only ever read (media files are fetched with GET).
 */
final class WordPressImporter
{
    /** WordPress page slug => template of the new site. */
    private const PAGE_TEMPLATES = [
        'home' => 'home', 'about' => 'about', 'contact-us' => 'contact', 'faqs' => 'faqs',
        'testimonials' => 'testimonials', 'for-customers' => 'for-customers', 'for-stylists' => 'for-stylists',
        'for-vendors' => 'for-vendors', 'thank-you' => 'thank-you', 'find-match' => 'find-match',
        'blog' => 'blog', 'shop' => 'shop', 'cart' => 'cart', 'checkout' => 'checkout', 'my-account' => 'account',
        'my-orders' => 'account', 'dashboard' => 'vendor-dashboard', 'store-listing' => 'store-listing',
    ];

    /** WordPress drafts/experiments that are not part of the public site. */
    private const SKIP_PAGES = ['find-match-2-0', 'vendor-onboarding', 'sample-page'];

    private HtmlSanitizer $sanitizer;
    private AutoParagraph $autop;
    /** @var array<int,int> wp attachment id => media id */
    private array $mediaMap = [];
    /** @var array<int,int> duplicate wp attachment id => media id of the same file */
    private array $duplicateMedia = [];
    /** @var callable(string):void */
    private $log;

    public function __construct(
        private WordPressSource $wp,
        private Database $db,
        private string $uploadsDir,
        private string $contentDir,
        ?callable $log = null,
    ) {
        $this->sanitizer = new HtmlSanitizer();
        $this->autop = new AutoParagraph();
        $this->log = $log ?? static function (string $line): void {
        };
    }

    public function run(array $steps, bool $download = true): void
    {
        foreach ($steps as $step) {
            ($this->log)("\n== {$step}");
            match ($step) {
                'media' => $this->importMedia($download),
                'categories' => $this->importCategories(),
                'posts' => $this->importPosts(),
                'pages' => $this->importPages(),
                'faqs' => $this->importFaqs(),
                'redirects' => $this->importRedirects(),
                default => throw new \InvalidArgumentException("Unknown import step '{$step}'."),
            };
        }
    }

    /* ------------------------------------------------------------------ media */

    public function importMedia(bool $download): void
    {
        $base = $this->wp->siteUrl() . '/wp-content/uploads/';
        $jobs = [];
        $count = 0;
        foreach ($this->wp->posts('attachment', ['inherit', 'publish', 'private']) as $post) {
            $meta = $this->wp->postMeta((int) $post['ID']);
            $file = (string) ($meta['_wp_attached_file'] ?? '');
            if (!$this->safeRelativePath($file)) {
                continue;
            }
            $info = is_array($meta['_wp_attachment_metadata'] ?? null) ? $meta['_wp_attachment_metadata'] : [];
            $dir = dirname($file) === '.' ? '' : dirname($file) . '/';

            $sizes = [];
            foreach ((array) ($info['sizes'] ?? []) as $name => $size) {
                $sizeFile = $dir . ($size['file'] ?? '');
                if (!is_array($size) || !$this->safeRelativePath($sizeFile)) {
                    continue;
                }
                $sizes[(string) $name] = ['path' => $sizeFile, 'width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)];
                $jobs[$base . $this->encodePath($sizeFile)] = $this->uploadsDir . '/' . $sizeFile;
            }
            $jobs[$base . $this->encodePath($file)] = $this->uploadsDir . '/' . $file;

            $row = [
                'path' => $file,
                'mime' => (string) $post['post_mime_type'],
                'width' => isset($info['width']) ? (int) $info['width'] : null,
                'height' => isset($info['height']) ? (int) $info['height'] : null,
                'filesize' => isset($info['filesize']) ? (int) $info['filesize'] : null,
                'alt' => mb_substr_safe(trim((string) ($meta['_wp_attachment_image_alt'] ?? '')), 255),
                'title' => mb_substr_safe(trim((string) $post['post_title']), 255),
                'sizes' => $sizes ? json_encode($sizes, JSON_UNESCAPED_SLASHES) : null,
                'legacy_wp_id' => (int) $post['ID'],
                'created_at' => $post['post_date_gmt'] !== '0000-00-00 00:00:00' ? $post['post_date_gmt'] : $post['post_date'],
            ];
            // WordPress allows several attachment records for one file; keep one media row per file.
            $existing = $this->db->first('SELECT id, legacy_wp_id FROM media WHERE path = ?', [$file]);
            if ($existing !== null && (int) $existing['legacy_wp_id'] !== $row['legacy_wp_id']) {
                $this->duplicateMedia[(int) $post['ID']] = (int) $existing['id'];
                continue;
            }
            $this->upsert('media', $row, ['legacy_wp_id' => $row['legacy_wp_id']]);
            $count++;
        }
        ($this->log)("{$count} media records, " . count($jobs) . ' files referenced');

        if ($download) {
            $done = 0;
            $total = count($jobs);
            $result = (new Downloader(6))->fetch($jobs, function (string $url, bool $ok, string $error) use (&$done, $total) {
                $done++;
                if (!$ok) {
                    ($this->log)("  ! {$url}: {$error}");
                } elseif ($done % 100 === 0) {
                    ($this->log)("  {$done} downloaded");
                }
            });
            ($this->log)("downloaded {$result['ok']}, already present {$result['skipped']}, failed " . count($result['failed']));
        }
        $this->loadMediaMap();
    }

    /* ------------------------------------------------------------- categories */

    public function importCategories(): void
    {
        $count = 0;
        foreach ($this->wp->terms('category') as $term) {
            if ($term['slug'] === 'uncategorized' && (int) $term['count'] === 0) {
                continue;
            }
            $meta = $this->wp->termMeta((int) $term['term_id']);
            $this->upsert('post_categories', [
                'slug' => $term['slug'],
                'name' => html_entity_decode((string) $term['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'description' => trim(strip_tags((string) $term['description'])) ?: null,
                'seo_title' => $this->seoText($meta['rank_math_title'] ?? null, ['term' => $term['name']]),
                'seo_description' => $this->seoText($meta['rank_math_description'] ?? null, ['term' => $term['name'], 'term_description' => strip_tags((string) $term['description'])]),
                'legacy_wp_id' => (int) $term['term_id'],
            ], ['slug' => $term['slug']]);
            $count++;
        }
        ($this->log)("{$count} categories");
    }

    /* ------------------------------------------------------------------ posts */

    public function importPosts(): void
    {
        $this->loadMediaMap();
        $categoryMap = [];
        foreach ($this->db->select('SELECT id, legacy_wp_id FROM post_categories') as $row) {
            $categoryMap[(int) $row['legacy_wp_id']] = (int) $row['id'];
        }
        $authors = [];
        foreach ($this->wp->db()->select("SELECT ID, display_name FROM {$this->wp->table('users')}") as $u) {
            $authors[(int) $u['ID']] = (string) $u['display_name'];
        }

        $count = 0;
        foreach ($this->wp->posts('post', ['publish', 'future', 'draft']) as $post) {
            $meta = $this->wp->postMeta((int) $post['ID']);
            $content = $this->convertContent((string) $post['post_content']);
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)));
            $excerpt = trim((string) $post['post_excerpt']) ?: $this->excerpt($plain, 160);

            $row = [
                'slug' => (string) $post['post_name'],
                'title' => html_entity_decode((string) $post['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'excerpt' => $excerpt,
                'content' => $content,
                'featured_media_id' => $this->mediaMap[(int) ($meta['_thumbnail_id'] ?? 0)] ?? null,
                'author_name' => $authors[(int) $post['post_author']] ?? '',
                'status' => match ($post['post_status']) { 'publish' => 'published', 'future' => 'scheduled', default => 'draft' },
                'reading_minutes' => max(1, (int) round(str_word_count($plain) / 200)),
                'seo_title' => $this->seoText($meta['rank_math_title'] ?? null, ['title' => $post['post_title']]),
                'seo_description' => $this->seoText($meta['rank_math_description'] ?? null, ['title' => $post['post_title'], 'excerpt' => $excerpt]),
                'seo_robots' => $this->robots($meta['rank_math_robots'] ?? null),
                'legacy_wp_id' => (int) $post['ID'],
                'published_at' => $post['post_date_gmt'] !== '0000-00-00 00:00:00' ? $post['post_date_gmt'] : $post['post_date'],
                'updated_at' => $post['post_modified_gmt'] !== '0000-00-00 00:00:00' ? $post['post_modified_gmt'] : $post['post_modified'],
            ];
            $id = $this->upsert('posts', $row, ['legacy_wp_id' => $row['legacy_wp_id']]);

            $this->db->delete('post_category', ['post_id' => $id]);
            foreach ($this->wp->objectTermIds((int) $post['ID'], 'category') as $termId) {
                if (isset($categoryMap[$termId])) {
                    $this->db->query('INSERT IGNORE INTO post_category (post_id, category_id) VALUES (?, ?)', [$id, $categoryMap[$termId]]);
                }
            }
            $count++;
        }
        ($this->log)("{$count} posts");
    }

    /* ------------------------------------------------------------------ pages */

    public function importPages(): void
    {
        $this->loadMediaMap();
        $count = 0;
        foreach ($this->wp->posts('page', ['publish', 'private']) as $page) {
            $slug = (string) $page['post_name'];
            if (in_array($slug, self::SKIP_PAGES, true)) {
                continue;
            }
            $meta = $this->wp->postMeta((int) $page['ID']);
            $template = self::PAGE_TEMPLATES[$slug] ?? 'default';
            $usesBuilder = ($meta['_elementor_edit_mode'] ?? '') === 'builder';

            // Plain content only for pages that are not built from designed sections.
            $content = ($template === 'default' && trim((string) $page['post_content']) !== '')
                ? $this->convertContent((string) $page['post_content'])
                : null;

            $data = null;
            $file = $this->contentDir . '/' . $slug . '.php';
            if (is_file($file)) {
                $data = json_encode(require $file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif ($template === 'default' && $usesBuilder && $content === null) {
                ($this->log)("  ! page '{$slug}' is built with Elementor but has no content file");
            }

            $title = html_entity_decode((string) $page['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->upsert('pages', [
                'slug' => $slug,
                'title' => $title,
                'template' => $template,
                'content' => $content,
                'data' => $data,
                'status' => $page['post_status'] === 'publish' ? 'published' : 'draft',
                'seo_title' => $this->seoText($meta['rank_math_title'] ?? null, ['title' => $title]),
                'seo_description' => $this->seoText($meta['rank_math_description'] ?? null, ['title' => $title]),
                'seo_robots' => $this->robots($meta['rank_math_robots'] ?? null),
                'og_image_id' => $this->mediaMap[(int) ($meta['rank_math_facebook_image_id'] ?? 0)] ?? null,
                'legacy_wp_id' => (int) $page['ID'],
                'published_at' => $page['post_date_gmt'] !== '0000-00-00 00:00:00' ? $page['post_date_gmt'] : $page['post_date'],
            ], ['slug' => $slug]);
            $count++;
        }
        ($this->log)("{$count} pages");
    }

    /* ------------------------------------------------------------------- faqs */

    /** FAQs live in nested accordions on the Elementor FAQ page, grouped by the heading above each. */
    public function importFaqs(): void
    {
        $page = $this->wp->db()->first("SELECT ID FROM {$this->wp->table('posts')} WHERE post_type = 'page' AND post_name = 'faqs' AND post_status = 'publish'");
        if ($page === null) {
            ($this->log)('no faqs page');
            return;
        }
        $tree = json_decode((string) ($this->wp->postMeta((int) $page['ID'])['_elementor_data'] ?? '[]'), true) ?: [];

        $faqs = [];
        $heading = 'General';
        $walk = function (array $nodes) use (&$walk, &$faqs, &$heading): void {
            foreach ($nodes as $node) {
                $type = $node['widgetType'] ?? $node['elType'] ?? '';
                $settings = $node['settings'] ?? [];
                if ($type === 'heading' && !empty($settings['title'])) {
                    $heading = trim(strip_tags((string) $settings['title']));
                }
                if ($type === 'nested-accordion') {
                    foreach (($settings['items'] ?? []) as $i => $item) {
                        $answer = '';
                        $this->collectText($node['elements'][$i] ?? [], $answer);
                        $question = trim(strip_tags((string) ($item['item_title'] ?? '')));
                        if ($question !== '' && $answer !== '') {
                            $faqs[] = [$heading, $question, $this->sanitizer->clean($answer)];
                        }
                    }
                    continue;
                }
                $walk($node['elements'] ?? []);
            }
        };
        $walk($tree);

        $this->db->transaction(function () use ($faqs) {
            $this->db->query('DELETE FROM faqs');
            foreach ($faqs as $i => [$group, $question, $answer]) {
                $this->db->insert('faqs', ['group_name' => $group, 'question' => $question, 'answer' => $answer, 'sort_order' => $i]);
            }
        });
        ($this->log)(count($faqs) . ' faqs');
    }

    /* -------------------------------------------------------------- redirects */

    public function importRedirects(): void
    {
        $count = 0;
        $rows = $this->wp->db()->select("SELECT sources, url_to, header_code, status FROM {$this->wp->table('rank_math_redirections')} WHERE status = 'active'");
        foreach ($rows as $row) {
            $sources = WordPressSource::maybeUnserialize((string) $row['sources']);
            $to = $this->rewriteUrls(trim((string) $row['url_to']));
            foreach (is_array($sources) ? $sources : [] as $source) {
                if (($source['comparison'] ?? 'exact') !== 'exact' || empty($source['pattern'])) {
                    continue;
                }
                $from = '/' . trim((string) parse_url('/' . ltrim((string) $source['pattern'], '/'), PHP_URL_PATH), '/') . '/';
                if ($from === '//' || $to === '') {
                    continue;
                }
                $this->upsert('redirects', [
                    'from_path' => mb_substr_safe($from, 191),
                    'to_url' => mb_substr_safe($to, 500),
                    'status_code' => in_array((int) $row['header_code'], [301, 302, 307, 308, 410, 451], true) ? (int) $row['header_code'] : 301,
                ], ['from_path' => $from]);
                $count++;
            }
        }
        // WordPress also redirects renamed posts/products from their old slug (_wp_old_slug).
        $t = fn (string $n) => $this->wp->table($n);
        foreach ($this->wp->db()->select("SELECT m.meta_value AS old_slug, p.post_name AS slug, p.post_type FROM {$t('postmeta')} m JOIN {$t('posts')} p ON p.ID = m.post_id
                                          WHERE m.meta_key = '_wp_old_slug' AND p.post_status = 'publish' AND p.post_type IN ('post', 'product')") as $row) {
            $old = strtolower(trim((string) $row['old_slug']));
            $new = (string) $row['slug'];
            if ($old === '' || $old === $new || !preg_match('/^[a-z0-9_-]+$/', $old)) {
                continue;
            }
            $prefix = $row['post_type'] === 'product' ? '/product/' : '/';
            $table = $row['post_type'] === 'product' ? 'products' : 'posts';
            // Only when the new address exists here and the old one is not in use again.
            if (!$this->db->scalar("SELECT 1 FROM {$table} WHERE slug = ?", [$new]) || $this->db->scalar("SELECT 1 FROM {$table} WHERE slug = ?", [$old])
                || ($prefix === '/' && $this->db->scalar('SELECT 1 FROM pages WHERE slug = ?', [$old]))) {
                continue;
            }
            $this->upsert('redirects', ['from_path' => $prefix . $old . '/', 'to_url' => $prefix . $new . '/', 'status_code' => 301], ['from_path' => $prefix . $old . '/']);
            $count++;
        }
        ($this->log)("{$count} redirects");
    }

    /* ---------------------------------------------------------------- helpers */

    /** Classic-editor content → clean HTML with site-relative links. */
    public function convertContent(string $raw): string
    {
        // Custom shortcode for related products becomes a block the post template renders.
        // Enclosing form lists WordPress product IDs: [dn_related_products]12\n34[/dn_related_products]
        $raw = (string) preg_replace_callback('/\[dn_related_products[^\]]*\](.*?)\[\/dn_related_products\]/is', static function (array $m): string {
            preg_match_all('/\d+/', $m[1], $ids);
            return '<div data-block="related-products" data-ids="' . implode(',', array_slice($ids[0], 0, 12)) . '"></div>';
        }, $raw);
        $raw = (string) preg_replace('/\[dn_related_products[^\]]*\]/i', '<div data-block="related-products" data-ids=""></div>', $raw);
        // Drop any other shortcode tags that would otherwise show as text.
        $raw = (string) preg_replace('/\[\/?[a-z_][a-z0-9_-]*(?:\s[^\]]*)?\]/i', '', $raw);
        $html = str_contains($raw, '<p') ? $raw : $this->autop->convert($raw);
        return $this->sanitizer->clean($this->rewriteUrls($html));
    }

    /** Absolute links to the old site become site-relative, so content works on any domain. */
    public function rewriteUrls(string $html): string
    {
        $host = (string) parse_url($this->wp->siteUrl(), PHP_URL_HOST);
        if ($host === '') {
            return $html;
        }
        $bare = preg_quote(preg_replace('/^www\./', '', $host), '#');
        $origin = "(?:https?:)?//(?:www\\.)?{$bare}";
        $html = (string) preg_replace("#{$origin}/wp-content/uploads/#i", '/uploads/', $html);
        return (string) preg_replace("#{$origin}(?=/|\"|'|\\s|$)#i", '', $html);
    }

    private function collectText(array $node, string &$html): void
    {
        $settings = $node['settings'] ?? [];
        if (($node['widgetType'] ?? '') === 'text-editor' && !empty($settings['editor'])) {
            $html .= $this->rewriteUrls((string) $settings['editor']);
        }
        foreach ($node['elements'] ?? [] as $child) {
            $this->collectText($child, $html);
        }
    }

    /** Resolve Rank Math variables into plain text; null when nothing custom is set. */
    public function seoText(mixed $template, array $vars): ?string
    {
        if (!is_string($template) || trim($template) === '') {
            return null;
        }
        $replace = [
            '%sep%' => (string) ($this->wp->option('rank-math-options-titles')['title_separator'] ?? '-'),
            '%sitename%' => (string) $this->wp->option('blogname', ''),
            '%sitedesc%' => (string) $this->wp->option('blogdescription', ''),
            '%page%' => '',
            '%currentyear%' => date('Y'),
        ];
        foreach ($vars as $key => $value) {
            $replace["%{$key}%"] = trim(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        $text = strtr($template, $replace);
        $text = (string) preg_replace('/%[a-z_]+%/', '', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return $text === '' ? null : mb_substr_safe($text, 255);
    }

    private function robots(mixed $robots): ?string
    {
        if (!is_array($robots) || $robots === []) {
            return null;
        }
        $robots = array_values(array_filter($robots, 'is_string'));
        return in_array('noindex', $robots, true) || in_array('nofollow', $robots, true) ? implode(', ', $robots) : null;
    }

    private function excerpt(string $plain, int $length): string
    {
        if (strlen($plain) <= $length) {
            return $plain;
        }
        $cut = substr($plain, 0, $length);
        $cut = substr($cut, 0, (int) strrpos($cut, ' ')) ?: $cut;
        return rtrim($cut, " ,.;:-") . '…';
    }

    private function loadMediaMap(): void
    {
        $this->mediaMap = [];
        foreach ($this->db->select('SELECT id, legacy_wp_id FROM media WHERE legacy_wp_id IS NOT NULL') as $row) {
            $this->mediaMap[(int) $row['legacy_wp_id']] = (int) $row['id'];
        }
        $this->mediaMap += $this->duplicateMedia;
    }

    /** Insert or update by a unique key; returns the row id. */
    public function upsert(string $table, array $row, array $key): int
    {
        $where = implode(' AND ', array_map(fn ($c) => $this->db->ident($c) . ' = ?', array_keys($key)));
        $id = $this->db->scalar('SELECT id FROM ' . $this->db->ident($table) . " WHERE {$where}", array_values($key));
        if ($id !== null) {
            $this->db->update($table, $row, ['id' => (int) $id]);
            return (int) $id;
        }
        return $this->db->insert($table, $row);
    }

    private function safeRelativePath(string $path): bool
    {
        return $path !== '' && !str_contains($path, '..') && !str_starts_with($path, '/')
            && (bool) preg_match('#^[A-Za-z0-9._/@ ()+-]+$#', $path)
            && !preg_match('/\.(php\d?|phtml|phar|htaccess|cgi|pl|sh)$/i', $path);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
