<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Seo;

/**
 * Cart page and cart actions. Every action works without JavaScript (form
 * post + redirect) and returns JSON for the drawer when called via fetch.
 */
final class CartController extends Controller
{
    public function show(Request $request): Response
    {
        // WooCommerce "?add-to-cart=ID" links from old emails/ads: open that product (adding needs a form post).
        if (ctype_digit((string) $request->query('add-to-cart', ''))) {
            $slug = $this->app->db()->scalar("SELECT slug FROM products WHERE legacy_wp_id = ? AND status = 'published'", [(int) $request->query('add-to-cart')])
                ?? $this->app->db()->scalar("SELECT p.slug FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.legacy_wp_id = ? AND p.status = 'published'", [(int) $request->query('add-to-cart')]);
            if ($slug) {
                return $this->redirect(link_to('/product/' . $slug . '/'), 302);
            }
        }
        $cart = $this->app->cart();
        $summary = $cart->summary();
        $seo = Seo::make('Cart');
        $seo->robots = 'noindex, follow';
        return $this->view('cart/show', [
            'seo' => $seo,
            'summary' => $summary,
            'notices' => array_merge($summary['notices'], (array) $this->app->session()->getFlash('cart_notices', [])),
            'couponError' => $this->app->session()->getFlash('coupon_error'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function add(Request $request): Response
    {
        $cart = $this->app->cart();
        $variantId = (int) $request->input('variant_id');
        $result = $variantId > 0 ? $cart->add($variantId, (int) $request->input('quantity', 1)) : ['ok' => false, 'message' => 'Please choose product options before adding to cart.'];

        if ($request->wantsJson()) {
            return $cart->attach($this->json($result + $this->drawerPayload()));
        }
        $slug = (string) $this->app->db()->scalar('SELECT slug FROM products WHERE id = ?', [(int) $request->input('product_id')]);
        $this->app->session()->flash('cart_notice', $result['message']);
        return $cart->attach($this->redirect($result['ok'] ? '/cart/' : ($slug !== '' ? '/product/' . $slug . '/' : '/shop/'), 303));
    }

    public function update(Request $request): Response
    {
        $cart = $this->app->cart();
        $quantities = $request->input('quantity', []);
        foreach (is_array($quantities) ? $quantities : [] as $variantId => $qty) {
            $cart->setQuantity((int) $variantId, (int) $qty);
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => true] + $this->drawerPayload());
        }
        $this->app->session()->flash('cart_notices', ['Cart updated.']);
        return $this->redirect('/cart/', 303);
    }

    public function remove(Request $request): Response
    {
        $this->app->cart()->remove((int) $request->input('variant_id'));
        if ($request->wantsJson()) {
            return $this->json(['ok' => true] + $this->drawerPayload());
        }
        return $this->redirect((string) $request->input('return') === 'checkout' ? '/checkout/' : '/cart/', 303);
    }

    public function coupon(Request $request): Response
    {
        $error = $this->app->cart()->applyCoupon($request->string('coupon_code'), $request->string('email') ?: null);
        if ($request->wantsJson()) {
            return $this->json(['ok' => $error === null, 'message' => $error ?? 'Coupon code applied successfully.']);
        }
        $error !== null
            ? $this->app->session()->flash('coupon_error', $error)
            : $this->app->session()->flash('cart_notices', ['Coupon code applied successfully.']);
        return $this->redirect((string) $request->input('return') === 'checkout' ? '/checkout/' : '/cart/', 303);
    }

    public function removeCoupon(Request $request): Response
    {
        $this->app->cart()->removeCoupon($request->string('coupon_code'));
        if ($request->wantsJson()) {
            return $this->json(['ok' => true]);
        }
        return $this->redirect((string) $request->input('return') === 'checkout' ? '/checkout/' : '/cart/', 303);
    }

    public function points(Request $request): Response
    {
        if (!$this->app->auth()->check()) {
            return $this->redirect('/my-account/', 303);
        }
        $this->app->cart()->setPointsToRedeem($request->input('remove') ? 0 : max(0, (int) $request->input('points')));
        return $this->redirect((string) $request->input('return') === 'checkout' ? '/checkout/' : '/cart/', 303);
    }

    public function shipping(Request $request): Response
    {
        $this->app->cart()->chooseShipping((int) $request->input('vendor_id'), $request->string('method'));
        if ($request->wantsJson()) {
            return $this->json(['ok' => true]);
        }
        return $this->redirect((string) $request->input('return') === 'checkout' ? '/checkout/' : '/cart/', 303);
    }

    /** Drawer contents + header totals (GET; no session needed). */
    public function summary(Request $request): Response
    {
        return $this->json($this->drawerPayload());
    }

    private function drawerPayload(): array
    {
        $summary = $this->app->cart()->summary();
        return [
            'count' => $summary['item_count'],
            'subtotal' => money($summary['totals']['subtotal_cents']),
            'drawer' => $this->app->view()->render('cart/drawer', ['summary' => $summary]),
        ];
    }

    private function json(array $data): Response
    {
        return Response::json($data)->header('Cache-Control', 'private, no-store');
    }
}
