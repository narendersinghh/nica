<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Minimal Stripe REST client (no SDK): form-encoded requests over TLS with
 * idempotency keys, plus webhook signature verification.
 */
final class StripeClient
{
    private const BASE = 'https://api.stripe.com/v1/';

    public function __construct(private string $secretKey)
    {
        if (!preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secretKey)) {
            throw new \InvalidArgumentException('Invalid Stripe secret key.');
        }
    }

    public function isTestMode(): bool
    {
        return str_contains($this->secretKey, '_test_');
    }

    /** @throws StripeException */
    public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        if (!function_exists('curl_init')) {
            throw new StripeException('The curl extension is required for Stripe.');
        }
        $url = self::BASE . ltrim($path, '/');
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        $headers = ['Authorization: Bearer ' . $this->secretKey, 'Content-Type: application/x-www-form-urlencoded'];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init($method === 'GET' && $body !== '' ? $url . '?' . $body : $url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new StripeException('Could not reach Stripe: ' . $error);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new StripeException('Unexpected response from Stripe.');
        }
        if ($status >= 400) {
            $err = $data['error'] ?? [];
            throw new StripeException((string) ($err['message'] ?? 'Stripe error'), (string) ($err['code'] ?? ''), (string) ($err['decline_code'] ?? ''), $status);
        }
        return $data;
    }

    /**
     * Verify a webhook payload against the Stripe-Signature header.
     * @throws StripeException when the signature is invalid or too old
     */
    public static function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || !$signatures || $secret === '') {
            throw new StripeException('Invalid signature header.');
        }
        if (abs(time() - $timestamp) > $tolerance) {
            throw new StripeException('Webhook timestamp outside tolerance.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $event = json_decode($payload, true);
                if (!is_array($event) || !isset($event['id'], $event['type'])) {
                    throw new StripeException('Malformed event.');
                }
                return $event;
            }
        }
        throw new StripeException('Signature mismatch.');
    }
}
