<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Seo;

/** Vendor directory (/store-listing/) and storefronts (/store/<slug>/). */
final class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $sort = $request->string('sort', 'recent');
        $search = mb_substr_safe($request->string('q'), 60);
        $vendors = $this->app->catalog()->vendors(in_array($sort, ['recent', 'name', 'rating', 'featured'], true) ? $sort : 'recent', $search);
        $this->app->images()->prime(array_merge(array_column($vendors, 'logo_media_id'), array_column($vendors, 'banner_media_id')));

        return $this->view('stores/index', [
            'seo' => Seo::make('Store List', null, 'Browse verified hair extension and wig vendors on The NiCa Collection.'),
            'vendors' => $vendors,
            'sort' => $sort,
            'search' => $search,
        ]);
    }

    public function show(Request $request): Response
    {
        $vendor = $this->vendor($request);
        $pageNo = (int) $request->param('page', '1');
        $result = $this->app->catalog()->products(['vendor' => (int) $vendor['id'], 'sort' => 'latest'], $pageNo);
        if ($pageNo > $result['pages']) {
            throw new HttpException(404);
        }
        return $this->view('stores/show', [
            'seo' => Seo::make($vendor['store_name'], null, $vendor['tagline'] ?: 'Shop ' . $vendor['store_name'] . ' on The NiCa Collection: verified premium hair.')
                ->image(image_url($vendor['banner_media_id'] ?: config('catalog.default_store_banner'))),
            'vendor' => $vendor,
            'tab' => 'products',
            'result' => $result,
        ]);
    }

    public function reviews(Request $request): Response
    {
        $vendor = $this->vendor($request);
        return $this->view('stores/show', [
            'seo' => Seo::make($vendor['store_name'] . ' Reviews'),
            'vendor' => $vendor,
            'tab' => 'reviews',
            'reviews' => $this->app->catalog()->vendorReviews((int) $vendor['id']),
        ]);
    }

    private function vendor(Request $request): array
    {
        return $this->app->catalog()->vendorBySlug((string) $request->param('slug')) ?? throw new HttpException(404);
    }
}
