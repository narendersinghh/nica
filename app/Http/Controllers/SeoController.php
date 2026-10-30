<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ProductFeed;
use App\Services\Settings;
use App\Services\Sitemap;

/** robots.txt, XML sitemaps (Rank Math file names) and the Google Merchant product feed. */
final class SeoController extends Controller
{
    public function robots(Request $request): Response
    {
        $base = $this->app->url()->base();
        if (!$this->app->isProduction()) {
            // Staging and local copies must never be indexed.
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $body = implode("\n", [
                'User-agent: *',
                'Disallow: /cart/',
                'Disallow: /checkout/',
                'Disallow: /my-account/',
                'Disallow: /my-orders/',
                'Disallow: /dashboard/',
                'Disallow: /admin/',
                'Disallow: /api/',
                'Disallow: /*?s=',
                'Disallow: /*?*add-to-cart=',
                'Disallow: /*?*ref=',
                'Disallow: /*?*orderby=',
                '',
                'Sitemap: ' . $base . '/sitemap_index.xml',
                '',
            ]);
        }
        return (new Response($body))->header('Content-Type', 'text/plain; charset=utf-8')->header('Cache-Control', 'public, max-age=3600');
    }

    public function index(Request $request): Response
    {
        return $this->xml((new Sitemap($this->app->db()))->index());
    }

    public function sitemap(Request $request): Response
    {
        $type = (string) $request->param('type');
        if (!in_array($type, Sitemap::TYPES, true)) {
            throw new HttpException(404);
        }
        return $this->xml((new Sitemap($this->app->db()))->render($type));
    }

    public function productFeed(Request $request): Response
    {
        if (!Settings::get('integrations.google_merchant.enabled', false)) {
            throw new HttpException(404);
        }
        return $this->xml((new ProductFeed($this->app->db()))->render(), 'public, max-age=900');
    }

    private function xml(string $body, string $cache = 'public, max-age=3600'): Response
    {
        return (new Response($body))
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex, follow')
            ->header('Cache-Control', $cache);
    }
}
