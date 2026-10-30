<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Catalog\Catalog;
use App\Services\Seo;

/** Product listings: /shop/, /collection/<path>/, /hair-texture/<slug>/ and search. */
final class ShopController extends Controller
{
    public function shop(Request $request): Response
    {
        $search = mb_substr_safe($request->string('s'), 100);
        if ($search !== '') {
            return $this->search($request, $search);
        }
        return $this->listing($request, [], [
            'title' => 'Shop',
            'eyebrow' => 'Explore the NiCa Collection',
            'intro' => 'Browse a carefully selected range of textures, lengths, and colors created to suit every style, from everyday elegance to bold statement looks. Every product featured on NiCa meets our quality-first standards so you can shop with confidence.',
            'base' => '/shop/',
            'showTiles' => true,
            'seoTitle' => null,
            'seoDescription' => 'Shop premium hair extensions, wigs, bundles and closures from verified vendors on The NiCa Collection.',
        ]);
    }

    public function collection(Request $request): Response
    {
        $category = $this->app->catalog()->categoryByPath((string) $request->param('path')) ?? throw new HttpException(404);
        return $this->listing($request, ['category' => (int) $category['id']], [
            'title' => $category['name'],
            'eyebrow' => 'Explore All Products',
            'eyebrowUrl' => '/shop/',
            'intro' => $category['description'] ?: 'We believe shopping should be simple, enjoyable, and inspiring. Explore the products we\'ve found and discover something that\'s just right for you.',
            'base' => '/collection/' . $category['path'] . '/',
            'category' => $category,
            'showTiles' => false,
            'seoTitle' => $category['seo_title'],
            'seoDescription' => $category['seo_description'] ?: $category['description'],
        ]);
    }

    public function texture(Request $request): Response
    {
        $tag = $this->app->catalog()->tagBySlug((string) $request->param('slug')) ?? throw new HttpException(404);
        return $this->listing($request, ['tag' => (int) $tag['id']], [
            'title' => $tag['name'],
            'eyebrow' => 'Explore All Products',
            'eyebrowUrl' => '/shop/',
            'intro' => $tag['description'] ?: 'We believe shopping should be simple, enjoyable, and inspiring. Explore the products we\'ve found and discover something that\'s just right for you.',
            'base' => '/hair-texture/' . $tag['slug'] . '/',
            'showTiles' => false,
            'seoTitle' => null,
            'seoDescription' => $tag['name'] . ' hair extensions and wigs from verified vendors.',
        ]);
    }

    private function search(Request $request, string $search): Response
    {
        $response = $this->listing($request, ['search' => $search], [
            'title' => 'Search results for “' . $search . '”',
            'eyebrow' => 'Explore All Products',
            'eyebrowUrl' => '/shop/',
            'intro' => '',
            'base' => '/shop/',
            'search' => $search,
            'showTiles' => false,
            'seoTitle' => $search . ' - ' . config('app.name'),
            'seoDescription' => '',
            'robots' => 'noindex, follow',
        ], 'relevance');
        return $response;
    }

    private function listing(Request $request, array $filters, array $view, string $defaultSort = 'latest'): Response
    {
        $catalog = $this->app->catalog();
        $pageNo = (int) $request->param('page', '1');
        if ($request->param('page') === '1') {
            return $this->redirect($view['base'], 301);
        }
        $sort = $request->string('sort', $defaultSort);
        if (!isset(Catalog::SORTS[$sort]) && $sort !== 'relevance') {
            $sort = 'latest';
        }
        $filters['sort'] = $sort;
        $result = $catalog->products($filters, $pageNo);
        if ($pageNo > $result['pages']) {
            throw new HttpException(404);
        }

        $seo = Seo::make($view['title'], $view['seoTitle'], (string) $view['seoDescription']);
        if ($pageNo > 1) {
            $seo->title = $view['title'] . ' - Page ' . $pageNo . ' - ' . config('app.name');
        }
        if (!empty($view['robots'])) {
            $seo->robots($view['robots']);
        }
        if (!empty($view['category'])) {
            $seo->breadcrumbs(array_merge([['Shop', '/shop/']], $catalog->categoryTrail((int) $view['category']['id'])));
        } elseif ($view['base'] !== '/shop/') {
            $seo->breadcrumbs([['Shop', '/shop/'], [$view['title'], $view['base']]]);
        }
        // Sorted variants of a listing point search engines at the main URL.
        if ($request->query('sort') !== null) {
            $seo->canonical = $this->app->url()->to($pageNo > 1 ? $view['base'] . 'page/' . $pageNo . '/' : $view['base']);
        }

        return $this->view('shop/index', $view + [
            'seo' => $seo,
            'result' => $result,
            'sort' => $sort,
            'sorts' => Catalog::SORTS,
            'pills' => $catalog->topCategories(),
            'tiles' => $view['showTiles'] ? \App\Services\Catalog\Storefront::featuredCategories() : [],
        ]);
    }
}
