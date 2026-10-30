<?php

declare(strict_types=1);

namespace App\Services\Cart;

/**
 * Pure cart maths (no I/O): splits lines into vendor packages, applies coupons,
 * picks shipping per package, adds tax, and works out commission/earnings.
 * Everything is integer cents; rounding happens per line, like WooCommerce.
 *
 * Line shape: [variant_id, product_id, vendor_id, vendor_name, name, quantity,
 *              unit_cents, regular_cents, on_sale, category_ids[], shipping_class, ...]
 * Coupon shape: a coupons table row (+ decoded JSON lists).
 */
final class Pricing
{
    public function __construct(private array $rules)
    {
    }

    /**
     * @param array<int,array> $lines
     * @param array<int,array> $coupons validated coupons
     * @param array<int,string> $shippingChoices vendor_id => method key
     * @param array<int,float> $commissionRates vendor_id => percent (missing = platform default)
     */
    public function calculate(array $lines, array $coupons = [], array $shippingChoices = [], array $commissionRates = []): array
    {
        // 1. Line subtotals.
        foreach ($lines as &$line) {
            $line['subtotal_cents'] = $line['unit_cents'] * $line['quantity'];
            $line['discount_cents'] = 0;
            $line['vendor_discount_cents'] = 0;
            $line['platform_discount_cents'] = 0;
        }
        unset($line);
        $cartSubtotal = array_sum(array_column($lines, 'subtotal_cents'));

        // 2. Coupons (each computed on the undiscounted line, capped so a line never goes below 0).
        $couponTotals = [];
        foreach ($coupons as $coupon) {
            // Vendor coupons are funded by the vendor; platform coupons by whoever cost_bearer says.
            $bearer = !empty($coupon['vendor_id']) ? 'vendor' : ($coupon['cost_bearer'] ?? 'shared');
            $eligible = array_keys(array_filter($lines, fn ($l) => self::eligible($coupon, $l)));
            $amount = 0;
            if ($coupon['type'] === 'percent') {
                foreach ($eligible as $i) {
                    $amount += $this->discountLine($lines[$i], (int) round($lines[$i]['subtotal_cents'] * (float) $coupon['amount'] / 100), $bearer);
                }
            } elseif ($coupon['type'] === 'fixed_product') {
                foreach ($eligible as $i) {
                    $amount += $this->discountLine($lines[$i], (int) round((float) $coupon['amount'] * 100) * $lines[$i]['quantity'], $bearer);
                }
            } else { // fixed_cart: spread over eligible lines in proportion to their subtotal
                $pool = (int) round((float) $coupon['amount'] * 100);
                $base = array_sum(array_map(fn ($i) => $lines[$i]['subtotal_cents'], $eligible));
                $remaining = min($pool, $base);
                foreach ($eligible as $n => $i) {
                    $share = $n === array_key_last($eligible) ? $remaining : (int) floor(min($pool, $base) * $lines[$i]['subtotal_cents'] / max(1, $base));
                    $applied = $this->discountLine($lines[$i], $share, $bearer);
                    $remaining -= $applied;
                    $amount += $applied;
                }
            }
            $couponTotals[$coupon['code']] = ['coupon' => $coupon, 'discount_cents' => $amount];
        }

        // 3. Vendor packages.
        $packages = [];
        foreach ($lines as $line) {
            $vid = (int) $line['vendor_id'];
            $packages[$vid] ??= ['vendor_id' => $vid, 'vendor_name' => $line['vendor_name'], 'lines' => [], 'subtotal_cents' => 0, 'discount_cents' => 0];
            $packages[$vid]['lines'][] = $line;
            $packages[$vid]['subtotal_cents'] += $line['subtotal_cents'];
            $packages[$vid]['discount_cents'] += $line['discount_cents'];
        }

        $taxRate = !empty($this->rules['tax']['enabled']) ? (float) $this->rules['tax']['rate'] : 0.0;
        $totals = ['subtotal_cents' => $cartSubtotal, 'discount_cents' => 0, 'shipping_cents' => 0, 'tax_cents' => 0, 'total_cents' => 0];

        foreach ($packages as $vid => &$pkg) {
            $afterDiscount = $pkg['subtotal_cents'] - $pkg['discount_cents'];

            // 4. Shipping options for this package.
            $freeByCoupon = (bool) array_filter($couponTotals, fn ($c) => (int) $c['coupon']['free_shipping'] === 1 && self::appliesToVendor($c['coupon'], $vid));
            $options = ['flat_rate' => ['key' => 'flat_rate', 'title' => $this->rules['shipping']['flat_rate']['title'], 'cents' => $this->flatRate($pkg['lines'])]];
            $freeMin = (int) ($this->rules['shipping']['free_shipping']['min_cents'] ?? PHP_INT_MAX);
            if ($freeByCoupon || $afterDiscount >= $freeMin) {
                $options['free_shipping'] = ['key' => 'free_shipping', 'title' => $this->rules['shipping']['free_shipping']['title'], 'cents' => 0];
            }
            // Default to the cheapest option; keep the customer's choice when still offered.
            uasort($options, static fn ($a, $b) => $a['cents'] <=> $b['cents']);
            $chosen = $shippingChoices[$vid] ?? null;
            $selected = isset($options[$chosen]) ? $chosen : array_key_first($options);
            $pkg['shipping_options'] = array_values($options);
            $pkg['shipping_method'] = $selected;
            $pkg['shipping_title'] = $options[$selected]['title'];
            $pkg['shipping_cents'] = $options[$selected]['cents'];

            // 5. Tax per line on the discounted amount (+ shipping when configured).
            $tax = 0;
            foreach ($pkg['lines'] as &$l) {
                $l['tax_cents'] = (int) round(($l['subtotal_cents'] - $l['discount_cents']) * $taxRate / 100);
                $l['total_cents'] = $l['subtotal_cents'] - $l['discount_cents'];
                $tax += $l['tax_cents'];
            }
            unset($l);
            if (!empty($this->rules['tax']['on_shipping'])) {
                $tax += (int) round($pkg['shipping_cents'] * $taxRate / 100);
            }
            $pkg['tax_cents'] = $tax;
            $pkg['total_cents'] = $afterDiscount + $pkg['shipping_cents'] + $tax;

            // 6. Commission and vendor earning. Who carries a coupon decides the commission base.
            $rate = $commissionRates[$vid] ?? (float) $this->rules['commission_rate'];
            $vendorBornDiscount = array_sum(array_column($pkg['lines'], 'vendor_discount_cents'));
            $platformBornDiscount = array_sum(array_column($pkg['lines'], 'platform_discount_cents'));
            $commissionBase = $pkg['subtotal_cents'] - $this->sharedDiscount($pkg['lines']);
            $pkg['commission_rate'] = $rate;
            $pkg['commission_cents'] = (int) round($commissionBase * $rate / 100);
            $pkg['vendor_discount_cents'] = $vendorBornDiscount;
            $earning = $commissionBase - $pkg['commission_cents'] - $vendorBornDiscount;
            if (($this->rules['shipping_fee_recipient'] ?? 'vendor') === 'vendor') {
                $earning += $pkg['shipping_cents'];
            }
            if (($this->rules['tax_fee_recipient'] ?? 'vendor') === 'vendor') {
                $earning += $tax;
            }
            $pkg['vendor_earning_cents'] = $earning;
            $pkg['platform_discount_cents'] = $platformBornDiscount;

            $totals['discount_cents'] += $pkg['discount_cents'];
            $totals['shipping_cents'] += $pkg['shipping_cents'];
            $totals['tax_cents'] += $tax;
            $totals['total_cents'] += $pkg['total_cents'];
        }
        unset($pkg);

        return [
            'packages' => array_values($packages),
            'coupons' => array_values($couponTotals),
            'totals' => $totals,
            'item_count' => array_sum(array_column($lines, 'quantity')),
        ];
    }

    public static function eligible(array $coupon, array $line): bool
    {
        if (!self::appliesToVendor($coupon, (int) $line['vendor_id'])) {
            return false;
        }
        if ((int) $coupon['exclude_sale_items'] === 1 && !empty($line['on_sale'])) {
            return false;
        }
        $products = $coupon['product_ids'] ?? [];
        if ($products && !in_array((int) $line['product_id'], $products, true)) {
            return false;
        }
        if (in_array((int) $line['product_id'], $coupon['excluded_product_ids'] ?? [], true)) {
            return false;
        }
        $cats = $coupon['category_ids'] ?? [];
        if ($cats && !array_intersect($cats, $line['category_ids'] ?? [])) {
            return false;
        }
        return !array_intersect($coupon['excluded_category_ids'] ?? [], $line['category_ids'] ?? []);
    }

    public static function appliesToVendor(array $coupon, int $vendorId): bool
    {
        if (!empty($coupon['vendor_id'])) {
            return (int) $coupon['vendor_id'] === $vendorId;
        }
        $vendors = $coupon['vendor_ids'] ?? [];
        return !$vendors || in_array($vendorId, $vendors, true);
    }

    /** Apply up to $cents of discount to a line (never below zero); returns what was applied. */
    private function discountLine(array &$line, int $cents, string $bearer): int
    {
        $applied = max(0, min($cents, $line['subtotal_cents'] - $line['discount_cents']));
        $line['discount_cents'] += $applied;
        if ($bearer === 'vendor') {
            $line['vendor_discount_cents'] += $applied;
        } elseif ($bearer === 'platform') {
            $line['platform_discount_cents'] += $applied;
        }
        return $applied;
    }

    /** Flat rate "per class": each class in the package once, plus the no-class cost if any line has no class. */
    private function flatRate(array $lines): int
    {
        $rule = $this->rules['shipping']['flat_rate'];
        $classes = [];
        $noClass = false;
        foreach ($lines as $l) {
            $class = (string) ($l['shipping_class'] ?? '');
            if ($class !== '' && isset($rule['class_cents'][$class])) {
                $classes[$class] = (int) $rule['class_cents'][$class];
            } else {
                $noClass = true;
            }
        }
        return array_sum($classes) + ($noClass ? (int) $rule['no_class_cents'] : 0);
    }

    /** Part of the package discount that is shared by vendor and platform (reduces the commission base). */
    private function sharedDiscount(array $lines): int
    {
        $total = 0;
        foreach ($lines as $l) {
            $total += $l['discount_cents'] - $l['vendor_discount_cents'] - $l['platform_discount_cents'];
        }
        return $total;
    }
}
