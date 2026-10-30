<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vendor;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Content\HtmlSanitizer;
use App\Services\Media\ImageUploader;
use App\Services\Payments\StripeConnect;
use App\Services\Payments\StripeException;
use App\Services\Vendor\Earnings;

final class SettingsController extends VendorController
{
    public const SOCIAL = ['instagram' => 'Instagram', 'fb' => 'Facebook', 'youtube' => 'YouTube', 'twitter' => 'X (Twitter)', 'pinterest' => 'Pinterest'];

    public function store(Request $request): Response
    {
        $vendor = $this->vendor($request);
        return $this->panel($request, 'vendor/settings-store', [
            'title' => 'Store Settings',
            'tab' => 'settings',
            'address' => json_decode((string) $vendor['address'], true) ?: [],
            'social' => json_decode((string) $vendor['social'], true) ?: [],
        ]);
    }

    public function saveStore(Request $request): Response
    {
        $data = $this->validate($request, [
            'store_name' => 'required|string|min:2|max:100',
            'tagline' => 'nullable|string|max:160',
            'about' => 'nullable|string|max:5000',
            'phone' => 'nullable|string|max:40|regex:/^[0-9 +().-]{7,40}$/',
            'public_email' => 'nullable|email',
            'street_1' => 'nullable|string|max:150',
            'street_2' => 'nullable|string|max:150',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip' => 'nullable|string|max:20',
            'shipping_policy' => 'nullable|string|max:5000',
            'refund_policy' => 'nullable|string|max:5000',
            'vacation_mode' => 'nullable|boolean',
        ]);
        $vendor = $this->vendor($request);
        $social = [];
        foreach (array_keys(self::SOCIAL) as $key) {
            $url = trim((string) $request->post('social_' . $key, ''));
            if ($url !== '') {
                if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $url) || strlen($url) > 255) {
                    throw new \App\Core\Http\ValidationException(['social_' . $key => self::SOCIAL[$key] . ' must be a full https:// link.'], $request);
                }
                $social[$key] = $url;
            }
        }
        $address = array_filter([
            'street_1' => trim((string) ($data['street_1'] ?? '')), 'street_2' => trim((string) ($data['street_2'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')), 'state' => trim((string) ($data['state'] ?? '')),
            'zip' => trim((string) ($data['zip'] ?? '')), 'country' => 'US',
        ], static fn ($v) => $v !== '');
        $sanitizer = new HtmlSanitizer();
        $update = [
            'store_name' => trim($data['store_name']),
            'tagline' => ($t = trim((string) ($data['tagline'] ?? ''))) !== '' ? $t : null,
            'about' => ($t = trim($sanitizer->clean(nl2br(e((string) ($data['about'] ?? '')), false)))) !== '' ? $t : null,
            'phone' => ($t = trim((string) ($data['phone'] ?? ''))) !== '' ? $t : null,
            'public_email' => ($t = trim((string) ($data['public_email'] ?? ''))) !== '' ? $t : null,
            'address' => count($address) > 1 ? json_encode($address) : null,
            'social' => $social ? json_encode($social, JSON_UNESCAPED_SLASHES) : null,
            'shipping_policy' => ($t = trim((string) ($data['shipping_policy'] ?? ''))) !== '' ? $t : null,
            'refund_policy' => ($t = trim((string) ($data['refund_policy'] ?? ''))) !== '' ? $t : null,
            'vacation_mode' => !empty($data['vacation_mode']) ? 1 : 0,
        ];

        $uploader = new ImageUploader($this->app->db(), $this->app->path('public/uploads'));
        $userId = $this->app->auth()->id();
        foreach (['logo' => 'logo_media_id', 'banner' => 'banner_media_id'] as $field => $column) {
            if ($request->post('remove_' . $field)) {
                $update[$column] = null;
            }
            $files = ProductController::files($request->file($field));
            if ($files) {
                try {
                    $update[$column] = $uploader->store($files[0], $userId, $data['store_name'] . ' ' . $field);
                } catch (\InvalidArgumentException $e) {
                    $this->error(ucfirst($field) . ': ' . $e->getMessage());
                    return $this->back('/dashboard/settings/store/');
                }
            }
        }
        $this->app->db()->update('vendors', $update, ['id' => (int) $vendor['id']]);
        $this->notice('Store settings saved.');
        return $this->back('/dashboard/settings/store/');
    }

    public function payment(Request $request): Response
    {
        $vendor = $this->vendor($request);
        $earnings = new Earnings($this->app->db());
        $connect = new StripeConnect($this->app->db());
        if (!empty($vendor['stripe_account_id']) && !StripeConnect::isConnected($vendor) && $request->query('stripe') === 'return') {
            try {
                $vendor = $connect->refresh($vendor);
            } catch (StripeException $e) {
                $this->app->logger()->warning('Stripe account refresh failed', ['vendor' => $vendor['id'], 'error' => $e->getMessage()]);
            }
        }
        return $this->panel($request, 'vendor/settings-payment', [
            'title' => 'Payout Settings',
            'tab' => 'payment',
            'vendor' => $vendor,
            'details' => $earnings->details($vendor) ?? [],
            'connectAvailable' => $connect->available(),
            'connectSimulated' => $connect->simulated(),
            'connected' => StripeConnect::isConnected($vendor),
            'threshold' => $earnings->threshold($vendor),
        ]);
    }

    public function savePayment(Request $request): Response
    {
        $method = (string) $request->post('method');
        if ($method === 'paypal') {
            $data = $this->validate($request, ['paypal_email' => 'required|email']);
            $details = ['email' => strtolower(trim($data['paypal_email']))];
        } elseif ($method === 'bank') {
            $data = $this->validate($request, [
                'account_name' => 'required|string|max:100',
                'bank_name' => 'required|string|max:100',
                'routing_number' => 'required|regex:/^\d{9}$/',
                'account_number' => 'required|regex:/^\d{4,17}$/',
                'account_type' => 'required|in:checking,savings',
            ]);
            $details = array_map(static fn ($v) => trim((string) $v), $data);
        } else {
            $this->error('Choose PayPal or bank transfer.');
            return $this->back('/dashboard/settings/payment/');
        }
        (new Earnings($this->app->db()))->saveDetails($this->vendorId($request), $method, $details);
        $this->notice('Payout details saved. They are stored encrypted.');
        return $this->back('/dashboard/settings/payment/');
    }

    public function connectStripe(Request $request): Response
    {
        $vendor = $this->vendor($request);
        $connect = new StripeConnect($this->app->db());
        if (!$connect->available()) {
            $this->error('Stripe payouts are not available yet. Please use PayPal or bank transfer.');
            return $this->back('/dashboard/settings/payment/');
        }
        try {
            $user = $this->app->auth()->user();
            $target = $connect->onboardingUrl($vendor, (string) $user['email'], url('/dashboard/settings/payment/?stripe=return'), url('/dashboard/settings/payment/?stripe=refresh'));
        } catch (StripeException $e) {
            $this->app->logger()->error('Stripe Connect onboarding failed', ['vendor' => $vendor['id'], 'error' => $e->getMessage()]);
            $this->error('Stripe could not start the setup right now. Please try again in a few minutes.');
            return $this->back('/dashboard/settings/payment/');
        }
        if ($connect->simulated()) {
            $this->notice('Test mode: a simulated Stripe account is connected. New orders will split payments automatically.');
            return $this->back('/dashboard/settings/payment/');
        }
        return $this->redirect($target, 303);
    }

    public function stripeDashboard(Request $request): Response
    {
        try {
            $url = (new StripeConnect($this->app->db()))->dashboardUrl($this->vendor($request));
        } catch (StripeException $e) {
            $url = null;
        }
        if ($url === null) {
            $this->error('The Stripe dashboard is not available right now.');
            return $this->back('/dashboard/settings/payment/');
        }
        return $this->redirect($url, 303);
    }

    public function disconnectStripe(Request $request): Response
    {
        (new StripeConnect($this->app->db()))->disconnect($this->vendorId($request));
        $this->notice('Stripe disconnected. New orders will be paid out manually.');
        return $this->back('/dashboard/settings/payment/');
    }
}
