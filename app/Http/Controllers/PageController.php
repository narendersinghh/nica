<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Models\Page;
use App\Models\Post;
use App\Services\Seo;

final class PageController extends Controller
{
    /** Page records that only exist as placeholders for routes with their own controllers. */
    private const SYSTEM_TEMPLATES = ['shop', 'cart', 'checkout', 'account', 'vendor-dashboard', 'store-listing', 'blog'];

    public function home(Request $request): Response
    {
        // Old WordPress search URLs (/?s=term&post_type=product).
        if ($request->string('s') !== '') {
            return $this->redirect('/shop/?' . http_build_query(['s' => mb_substr_safe($request->string('s'), 100)]), 301);
        }
        // Old WordPress short links (/?p=123, /?page_id=45, /?product=slug) go to the page they pointed at.
        if (($legacy = LegacyController::shortLink($request)) !== null) {
            return $this->redirect($legacy, 301);
        }
        return $this->render(Page::findPublished('home') ?? throw new HttpException(404), $request);
    }

    /**
     * Root-level slug: a page first, then a blog post (WordPress shares this URL space),
     * then a stored redirect, else 404.
     */
    public function show(Request $request): Response
    {
        $slug = (string) $request->param('slug');

        if ($page = Page::findPublished($slug)) {
            if ($page['template'] === 'home') {
                return $this->redirect('/', 301);
            }
            // Shop, cart, account etc. are WordPress placeholder pages served by their own routes.
            if (in_array($page['template'], self::SYSTEM_TEMPLATES, true)) {
                throw new HttpException(404);
            }
            return $this->render($page, $request);
        }
        if ($post = Post::findPublished($slug)) {
            return (new BlogController($this->app))->post($request, $post);
        }
        throw new HttpException(404);
    }

    private function render(array $page, Request $request): Response
    {
        // Designed pages are lists of blocks; home has its own template; the rest are rich-text pages.
        $template = match (true) {
            $page['template'] === 'home' => 'home',
            !empty($page['data']['blocks']) => 'blocks',
            default => 'default',
        };
        $seo = Seo::make($page['title'], $page['seo_title'], $page['seo_description'] ?: ($page['content'] ? strip_tags((string) $page['content']) : ''))
            ->robots($page['seo_robots'])
            ->image($page['og_image_id'] ? image_url((int) $page['og_image_id']) : null);
        if ($template === 'home') {
            $seo->canonical = $this->app->url()->to('/');
            $seo->schema([
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => $this->app->url()->to('/'),
                'logo' => Seo::absolute(upload_url(config('site.logo'))),
                'sameAs' => array_column(config('site.social'), 'url'),
                'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'email' => (string) \App\Services\Settings::get('site.contact.email'), 'telephone' => (string) \App\Services\Settings::get('site.contact.phone')],
            ]);
            $seo->schema([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => config('app.name'),
                'url' => $this->app->url()->to('/'),
                'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->app->url()->to('/shop/') . '?s={search_term_string}'], 'query-input' => 'required name=search_term_string'],
            ]);
        } else {
            $seo->breadcrumbs([[$page['title'], '/' . $page['slug'] . '/']]);
        }

        return $this->view('pages/' . $template, [
            'page' => $page,
            'data' => $page['data'],
            'seo' => $seo,
        ]);
    }
}
