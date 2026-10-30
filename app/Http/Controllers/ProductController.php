<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Models\Page;
use App\Services\Seo;

final class ProductController extends Controller
{
    public function show(Request $request): Response
    {
        $catalog = $this->app->catalog();
        $product = $catalog->productBySlug((string) $request->param('slug')) ?? throw new HttpException(404);
        $id = (int) $product['id'];

        $images = $catalog->images($id) ?: array_filter([(int) $product['featured_media_id']]);
        $variants = $catalog->variants($id);
        $attributes = $catalog->productAttributes($id);
        $reviews = $catalog->reviews($id);
        $this->app->images()->prime(array_merge($images, array_filter(array_column($variants, 'image'))));

        $plainDescription = trim(strip_tags((string) ($product['short_description'] ?: $product['description'])));
        $seo = Seo::make($product['name'], $product['seo_title'], $product['seo_description'] ?: $plainDescription)
            ->image($images ? image_url($images[0], 'large') : null)
            ->type('product');
        $seo->schema($this->schema($product, $variants, $reviews, $images, $seo->canonical));
        $seo->breadcrumbs(array_merge([['Shop', '/shop/']], $catalog->categoryTrail($product['primary_category_id'] ? (int) $product['primary_category_id'] : null), [[$product['name'], '/product/' . $product['slug'] . '/']]));

        // Shipping tab: the vendor's own policy, else the platform policy page.
        $shipping = $product['shipping_policy'];
        if (!$shipping) {
            $page = Page::findPublished('shipping-cancellation');
            $shipping = $page['content'] ?? null;
        }

        return $this->view('shop/product', [
            'seo' => $seo,
            'product' => $product,
            'images' => array_values($images),
            'variants' => $variants,
            'attributes' => $attributes,
            'categories' => $catalog->productCategories($id),
            'reviews' => $reviews,
            'related' => $catalog->related($product, 4),
            'shipping' => $shipping,
            'errors' => $this->app->session()->getFlash('_errors', []),
            'reviewSent' => $this->app->session()->getFlash('review_sent', false),
            'cartNotice' => $this->app->session()->getFlash('cart_notice'),
        ]);
    }

    /** Review submission: stored as pending until approved by an admin or the vendor. */
    public function review(Request $request): Response
    {
        $product = $this->app->catalog()->productBySlug((string) $request->param('slug')) ?? throw new HttpException(404);
        $back = '/product/' . $product['slug'] . '/#reviews';
        if ($request->string('website') !== '') {
            $this->app->session()->flash('review_sent', true);
            return $this->redirect($back, 303);
        }
        $user = $this->app->auth()->user();
        $rules = ['rating' => 'required|integer|between:1,5', 'body' => 'required|string|min:10|max:3000'];
        if ($user === null) {
            $rules += ['author_name' => 'required|string|max:100', 'author_email' => 'required|email'];
        }
        $data = $this->validate($request, $rules);

        // The verified-buyer flag is set from order history once checkout exists (Phase 3).
        $this->app->db()->insert('reviews', [
            'product_id' => (int) $product['id'],
            'vendor_id' => (int) $product['vendor_id'],
            'user_id' => $user['id'] ?? null,
            'author_name' => $user ? ($user['display_name'] ?: $user['first_name']) : $data['author_name'],
            'author_email' => $user ? $user['email'] : strtolower($data['author_email']),
            'rating' => $data['rating'],
            'body' => $data['body'],
            'status' => 'pending',
            'is_verified_buyer' => 0,
            'ip' => $request->ip(),
        ]);
        $this->app->session()->flash('review_sent', true);
        return $this->redirect($back, 303);
    }

    private function schema(array $product, array $variants, array $reviews, array $images, ?string $url): array
    {
        $prices = array_column($variants, 'price');
        $inStock = (bool) array_filter(array_column($variants, 'in_stock'));
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'url' => $url,
            'description' => Seo::clip((string) ($product['short_description'] ?: $product['description']), 300),
            'image' => array_map(fn ($id) => $this->app->url()->base() . image_url($id), array_slice($images, 0, 4)),
            'sku' => $variants[0]['sku'] ?? null,
            'brand' => ['@type' => 'Brand', 'name' => $product['vendor_name']],
            'offers' => [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'USD',
                'lowPrice' => number_format(($prices ? min($prices) : 0) / 100, 2, '.', ''),
                'highPrice' => number_format(($prices ? max($prices) : 0) / 100, 2, '.', ''),
                'offerCount' => count($variants),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'seller' => ['@type' => 'Organization', 'name' => $product['vendor_name']],
            ],
        ];
        if ($reviews) {
            $schema['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (float) $product['rating_avg'], 'reviewCount' => (int) $product['rating_count']];
            $schema['review'] = array_map(static fn ($r) => [
                '@type' => 'Review',
                'author' => ['@type' => 'Person', 'name' => $r['author_name']],
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) $r['rating'], 'bestRating' => 5],
                'reviewBody' => $r['body'],
                'datePublished' => substr($r['created_at'], 0, 10),
            ], array_slice($reviews, 0, 5));
        }
        return $schema;
    }
}
