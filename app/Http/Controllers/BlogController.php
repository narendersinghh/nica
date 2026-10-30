<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Models\Page;
use App\Models\Post;
use App\Services\Catalog\Storefront;
use App\Services\Seo;

final class BlogController extends Controller
{
    private const PER_PAGE = 12; // WordPress "posts_per_page"

    public function index(Request $request): Response
    {
        $pageNo = (int) $request->param('page', '1');
        if ($pageNo === 1 && $request->param('page') !== null) {
            return $this->redirect('/blog/', 301);
        }
        $result = Post::paginate($pageNo, self::PER_PAGE);
        if ($pageNo > $result['pages']) {
            throw new HttpException(404);
        }
        $page = Page::findPublished('blog');
        $seo = Seo::make($page['title'] ?? 'Blog', $page['seo_title'] ?? null, $page['seo_description'] ?? 'Real insights, expert advice, and meaningful conversations about hair, beauty, and the marketplace behind it.');
        if ($pageNo > 1) {
            $seo->title = 'Blog - Page ' . $pageNo . ' - ' . config('app.name');
        }

        return $this->view('blog/index', [
            'seo' => $seo,
            'title' => 'Blog',
            'intro' => 'Welcome to the blog, your go-to space for real insights, expert advice, and meaningful conversations about hair, beauty, and the evolving marketplace behind it.',
            'result' => $result,
            'baseUrl' => '/blog/',
        ]);
    }

    public function category(Request $request): Response
    {
        $category = $this->app->db()->first('SELECT * FROM post_categories WHERE slug = ?', [(string) $request->param('slug')])
            ?? throw new HttpException(404);
        $pageNo = (int) $request->param('page', '1');
        $result = Post::paginate($pageNo, self::PER_PAGE, (int) $category['id']);
        if ($pageNo > $result['pages'] || $result['total'] === 0) {
            throw new HttpException(404);
        }
        $seo = Seo::make($category['name'], $category['seo_title'], $category['seo_description'] ?: $category['description']);

        return $this->view('blog/index', [
            'seo' => $seo,
            'title' => $category['name'],
            'intro' => (string) $category['description'],
            'result' => $result,
            'baseUrl' => '/category/' . $category['slug'] . '/',
        ]);
    }

    /** Called from PageController for root-level slugs that are posts. */
    public function post(Request $request, array $post): Response
    {
        $image = $post['featured_media_id'] ? image_url((int) $post['featured_media_id']) : null;
        $seo = Seo::make($post['title'], $post['seo_title'], $post['seo_description'] ?: $post['excerpt'])
            ->robots($post['seo_robots'])
            ->image($image)
            ->type('article');
        $seo->schema([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $post['title'],
            'datePublished' => date('c', strtotime($post['published_at'])),
            'dateModified' => date('c', strtotime($post['updated_at'])),
            'author' => ['@type' => 'Person', 'name' => $post['author_name'] ?: config('app.name')],
            'publisher' => ['@type' => 'Organization', 'name' => config('app.name'), 'logo' => ['@type' => 'ImageObject', 'url' => Seo::absolute(upload_url(config('site.logo')))]],
            'image' => $image ? Seo::absolute($image) : null,
            'mainEntityOfPage' => $seo->canonical,
        ]);
        $seo->breadcrumbs([['Blog', '/blog/'], [$post['title'], '/' . $post['slug'] . '/']]);

        // Related-products blocks inside content list old WordPress product IDs; the catalog renders them (Phase 2).
        $content = preg_replace_callback('#<div data-block="related-products"(?: data-ids="([0-9,]*)")?></div>#', function (array $m) use ($post) {
            $ids = array_values(array_filter(array_map('intval', explode(',', $m[1] ?? ''))));
            $products = Storefront::relatedForPost($post, $ids);
            return $products ? $this->app->view()->render('partials/related-products', ['products' => $products]) : '';
        }, $post['content']);

        return $this->view('blog/post', [
            'seo' => $seo,
            'post' => $post,
            'content' => $content,
            'latest' => Post::latest(6, (int) $post['id']),
            'related' => Post::related($post, 3),
            'shareUrl' => $seo->canonical,
        ]);
    }
}
