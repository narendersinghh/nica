<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Core\Database;

/** Coupon lookup and the WooCommerce-style validity rules. */
final class Coupons
{
    private const LISTS = ['product_ids', 'excluded_product_ids', 'category_ids', 'excluded_category_ids', 'vendor_ids', 'allowed_emails'];

    public static function find(Database $db, string $code): ?array
    {
        $row = $db->first("SELECT * FROM coupons WHERE code = ? AND status = 'active'", [trim($code)]);
        if ($row === null) {
            return null;
        }
        foreach (self::LISTS as $key) {
            $list = json_decode((string) ($row[$key] ?? ''), true) ?: [];
            $row[$key] = $key === 'allowed_emails' ? array_map('strtolower', $list) : array_map('intval', $list);
        }
        return $row;
    }

    /**
     * @param array $lines priced cart lines
     * @return string|null error message, null when the coupon can be used
     */
    public static function validate(Database $db, array $coupon, array $lines, ?string $email, ?int $userId): ?string
    {
        $now = gmdate('Y-m-d H:i:s');
        $code = $coupon['code'];
        if ($coupon['starts_at'] && $coupon['starts_at'] > $now) {
            return 'Coupon “' . $code . '” is not active yet.';
        }
        if ($coupon['expires_at'] && $coupon['expires_at'] < $now) {
            return 'This coupon has expired.';
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['usage_limit'] > 0 && (int) $coupon['usage_count'] >= (int) $coupon['usage_limit']) {
            return 'Coupon usage limit has been reached.';
        }
        if ($coupon['usage_limit_per_user'] !== null && (int) $coupon['usage_limit_per_user'] > 0 && ($email || $userId)) {
            $used = (int) $db->scalar(
                'SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND (email = ? OR (user_id IS NOT NULL AND user_id = ?))',
                [$coupon['id'], strtolower((string) $email), $userId ?? 0],
            );
            if ($used >= (int) $coupon['usage_limit_per_user']) {
                return 'You have already used coupon “' . $code . '” the maximum number of times.';
            }
        }
        if ($coupon['allowed_emails'] && $email !== null && !in_array(strtolower($email), $coupon['allowed_emails'], true)) {
            return 'Sorry, coupon “' . $code . '” is not valid for your email address.';
        }
        $subtotal = array_sum(array_map(static fn ($l) => $l['unit_cents'] * $l['quantity'], $lines));
        if ($coupon['minimum_cents'] !== null && (int) $coupon['minimum_cents'] > 0 && $subtotal < (int) $coupon['minimum_cents']) {
            return 'The minimum spend for this coupon is ' . money((int) $coupon['minimum_cents']) . '.';
        }
        if ($coupon['maximum_cents'] !== null && (int) $coupon['maximum_cents'] > 0 && $subtotal > (int) $coupon['maximum_cents']) {
            return 'The maximum spend for this coupon is ' . money((int) $coupon['maximum_cents']) . '.';
        }
        $eligible = array_filter($lines, static fn ($l) => Pricing::eligible($coupon, $l));
        if (!$eligible && !((int) $coupon['free_shipping'] === 1 && (float) $coupon['amount'] == 0.0 && $lines)) {
            return 'Sorry, coupon “' . $code . '” is not applicable to the items in your cart.';
        }
        return null;
    }
}
