<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Services\Audit;
use App\Services\Settings;

/**
 * Marketplace settings. Values are stored as overrides in the settings table
 * (one JSON document per config group); config/*.php keeps the defaults.
 * Secrets (Stripe keys, SMTP password, APP_KEY) stay in .env on purpose.
 */
final class SettingsController extends AdminController
{
    public function index(Request $request): Response
    {
        $stripeSecret = (string) Env::get('STRIPE_SECRET_KEY', '');
        return $this->panel($request, 'admin/settings', [
            'title' => 'Settings',
            'tab' => 'settings',
            'commerce' => Settings::get('commerce'),
            'catalog' => Settings::get('catalog'),
            'points' => Settings::get('points'),
            'site' => Settings::get('site'),
            'referrals' => Settings::get('referrals'),
            'integrations' => Settings::get('integrations'),
            'feedUrl' => $this->app->url()->to('/product-feed.xml'),
            'system' => [
                'Environment' => (string) config('app.env'),
                'Stripe' => $stripeSecret === '' ? 'Not configured' . ($this->app->isLocal() ? ' (local test payments are used)' : '') : (str_contains($stripeSecret, '_live_') ? 'Live mode' : 'Test mode'),
                'Stripe webhook secret' => Env::get('STRIPE_WEBHOOK_SECRET', '') !== '' ? 'Set' : 'Missing',
                'Email delivery' => (string) Env::get('MAIL_DRIVER', 'log'),
                'Allowed domains' => (string) Env::get('TRUSTED_HOSTS', ''),
                'PHP' => PHP_VERSION,
            ],
        ]);
    }

    public function save(Request $request): Response
    {
        $this->authorize('settings');
        $cents = static fn ($v) => (int) round((float) $v * 100);
        $data = $this->validate($request, [
            'commission_rate' => 'required|numeric|min:0|max:100',
            'payout_threshold' => 'required|numeric|min:0|max:100000',
            'tax_rate' => 'required|numeric|min:0|max:30',
            'flat_rate' => 'required|numeric|min:0|max:1000',
            'free_shipping_min' => 'nullable|numeric|min:0|max:100000',
            'stock_hold_minutes' => 'required|integer|min:10|max:1440',
            'signup_points' => 'required|integer|min:0|max:100000',
            'points_per_dollar_redeemed' => 'required|integer|min:1|max:100000',
            'points_per_dollar_spent' => 'required|integer|min:0|max:1000',
            'announcement' => 'nullable|string|max:200',
            'contact_email' => 'required|email',
            'contact_phone' => 'nullable|string|max:40',
            'ref_cookie_days' => 'required|integer|min:1|max:365',
            'ref_threshold' => 'required|numeric|min:0|max:100000',
            'ref_window_days' => 'required|integer|min:7|max:365',
            'ga4_id' => 'nullable|regex:/^G-[A-Za-z0-9]{4,15}$/',
            'meta_pixel_id' => 'nullable|regex:/^\d{6,20}$/',
            'klaviyo_key' => 'nullable|regex:/^[A-Za-z0-9]{6}$/',
        ]);
        $tiers = [];
        foreach ((array) Settings::get('referrals.tiers', []) as $i => $t) {
            $rate = $request->input('tier_rate')[$t['key']] ?? null;
            $min = $request->input('tier_min')[$t['key']] ?? null;
            if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 50 || !is_numeric($min) || (float) $min < 0) {
                throw new ValidationException(['tiers' => 'Tier rates must be 0–50% and thresholds 0 or more.'], $request);
            }
            $tiers[] = ['key' => $t['key'], 'name' => $t['name'], 'min_cents' => $i === 0 ? 0 : $cents($min), 'rate' => round((float) $rate, 2)];
        }
        $classCents = [];
        foreach ((array) $request->post('class_cents', []) as $slug => $value) {
            if (!preg_match('/^[a-z0-9-]{1,50}$/', (string) $slug) || !is_numeric($value) || (float) $value < 0) {
                throw new ValidationException(['class_cents' => 'Shipping class prices must be numbers.'], $request);
            }
            $classCents[$slug] = $cents($value);
        }

        $groups = [
            'commerce' => [
                'commission_rate' => round((float) $data['commission_rate'], 2),
                'payout_threshold_cents' => $cents($data['payout_threshold']),
                'tax' => ['enabled' => (bool) $request->post('tax_enabled'), 'rate' => round((float) $data['tax_rate'], 3)],
                'shipping' => [
                    'flat_rate' => ['no_class_cents' => $cents($data['flat_rate']), 'class_cents' => $classCents],
                    'free_shipping' => ['min_cents' => ($data['free_shipping_min'] ?? '') === '' || $data['free_shipping_min'] === null ? null : $cents($data['free_shipping_min'])],
                ],
                'stock_hold_minutes' => (int) $data['stock_hold_minutes'],
            ],
            'catalog' => ['product_review' => (bool) $request->post('product_review')],
            'points' => [
                'enabled' => (bool) $request->post('points_enabled'),
                'signup_points' => (int) $data['signup_points'],
                'points_per_dollar_redeemed' => (int) $data['points_per_dollar_redeemed'],
                'points_per_dollar_spent' => (int) $data['points_per_dollar_spent'],
            ],
            'referrals' => [
                'enabled' => (bool) $request->post('ref_enabled'),
                'auto_approve' => (bool) $request->post('ref_auto_approve'),
                'cookie_days' => (int) $data['ref_cookie_days'],
                'payout_threshold_cents' => $cents($data['ref_threshold']),
                'tier_window_days' => (int) $data['ref_window_days'],
                'tiers' => $tiers,
            ],
            'integrations' => [
                'ga4' => ['measurement_id' => strtoupper(trim((string) ($data['ga4_id'] ?? '')))],
                'meta_pixel' => ['pixel_id' => trim((string) ($data['meta_pixel_id'] ?? ''))],
                'klaviyo' => ['public_key' => trim((string) ($data['klaviyo_key'] ?? ''))],
                'google_merchant' => ['enabled' => (bool) $request->post('google_feed')],
            ],
            'site' => [
                'announcement' => trim((string) ($data['announcement'] ?? '')),
                'contact' => [
                    'email' => strtolower(trim($data['contact_email'])),
                    'phone' => trim((string) ($data['contact_phone'] ?? '')),
                    'phone_href' => 'tel:' . preg_replace('/[^0-9+]/', '', (string) ($data['contact_phone'] ?? '')),
                ],
            ],
        ];
        $db = $this->app->db();
        $changes = [];
        foreach ($groups as $group => $values) {
            $before = json_decode((string) $db->scalar('SELECT value FROM settings WHERE name = ?', [$group]), true) ?: [];
            $after = array_replace_recursive($before, $values);
            // Class prices are replaced as a whole (a removed class must disappear).
            if ($group === 'commerce') {
                $after['shipping']['flat_rate']['class_cents'] = $classCents;
            }
            if ($group === 'referrals') {
                $after['tiers'] = $tiers; // lists are replaced, not merged
            }
            if ($after != $before) {
                $db->query('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$group, json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $changes[$group] = $values;
            }
        }
        Settings::flush();
        if ($changes) {
            Audit::log('settings.updated', 'settings', null, $changes);
        }
        $this->notice($changes ? 'Settings saved. New carts and orders use them right away.' : 'Nothing changed.');
        return $this->back('/admin/settings/');
    }
}
