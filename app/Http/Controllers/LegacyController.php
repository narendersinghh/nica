<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;

/** Permanent redirects for old WordPress URLs. */
final class LegacyController extends Controller
{
    public function upload(Request $request): Response
    {
        $path = (string) $request->param('path');
        if (str_contains($path, '..') || !is_file($this->app->path('public/uploads/' . $path))) {
            throw new HttpException(404);
        }
        return $this->redirect(upload_url($path), 301);
    }

    public function login(Request $request): Response
    {
        return $this->redirect('/my-account/', 301);
    }

    /** WordPress core's /sitemap.xml and /wp-sitemap.xml point to the Rank Math-style index. */
    public function sitemap(Request $request): Response
    {
        return $this->redirect(link_to('/sitemap_index.xml'), 301);
    }

    public function gone(Request $request): Response
    {
        throw new HttpException(410, 'This page has been removed.');
    }

    /** /product-category/{path}/ was WooCommerce's default category base; the live site uses /collection/. */
    public function productCategory(Request $request): Response
    {
        return $this->redirect(link_to('/collection/' . $request->param('path') . '/'), 301);
    }

    /** Target of an old WordPress short link (?p=, ?page_id=, ?product=), or null. */
    public static function shortLink(Request $request): ?string
    {
        $db = app()->db();
        $id = (int) ($request->query('p') ?? $request->query('page_id') ?? 0);
        if ($id > 0) {
            if ($slug = $db->scalar("SELECT slug FROM posts WHERE legacy_wp_id = ? AND status = 'published'", [$id])) {
                return link_to('/' . $slug . '/');
            }
            if ($slug = $db->scalar("SELECT slug FROM pages WHERE legacy_wp_id = ? AND status = 'published'", [$id])) {
                return link_to($slug === 'home' ? '/' : '/' . $slug . '/');
            }
            if ($slug = $db->scalar("SELECT slug FROM products WHERE legacy_wp_id = ? AND status = 'published'", [$id])) {
                return link_to('/product/' . $slug . '/');
            }
        }
        $product = $request->query('product');
        if (is_string($product) && preg_match('/^[a-z0-9-]{1,191}$/', $product) && $db->scalar("SELECT 1 FROM products WHERE slug = ? AND status = 'published'", [$product])) {
            return link_to('/product/' . $product . '/');
        }
        return null;
    }
}
