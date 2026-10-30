<?php

declare(strict_types=1);

use App\Services\Cart\Pricing;

$rules = [
    'tax' => ['enabled' => true, 'rate' => 9.75, 'on_shipping' => false],
    'shipping' => [
        'flat_rate' => ['title' => 'Flat rate', 'no_class_cents' => 1499, 'class_cents' => ['american-wigs' => 2999]],
        'free_shipping' => ['title' => 'Free shipping', 'min_cents' => 15000],
    ],
    'commission_rate' => 15.0,
    'shipping_fee_recipient' => 'vendor',
    'tax_fee_recipient' => 'vendor',
];
$line = static fn (int $vendor, int $unit, int $qty = 1, array $extra = []) => $extra + [
    'variant_id' => random_int(1, 1_000_000), 'product_id' => $extra['product_id'] ?? 1, 'vendor_id' => $vendor, 'vendor_name' => "V{$vendor}",
    'name' => 'Item', 'quantity' => $qty, 'unit_cents' => $unit, 'regular_cents' => $unit, 'on_sale' => false, 'category_ids' => [], 'shipping_class' => null,
];
$coupon = static fn (array $c) => $c + [
    'code' => 'TEST', 'type' => 'percent', 'amount' => 10, 'vendor_id' => null, 'cost_bearer' => 'shared', 'free_shipping' => 0,
    'exclude_sale_items' => 0, 'product_ids' => [], 'excluded_product_ids' => [], 'category_ids' => [], 'excluded_category_ids' => [], 'vendor_ids' => [],
];

test('pricing: tax 9.75% on items only, flat rate per vendor package', function () use ($rules, $line) {
    $r = (new Pricing($rules))->calculate([$line(1, 5500), $line(2, 2999, 2)]);
    assert_same(2, count($r['packages']));
    assert_same(5500 + 5998, $r['totals']['subtotal_cents']);
    assert_same(1499 * 2, $r['totals']['shipping_cents'], 'flat rate charged per vendor');
    assert_same((int) round(5500 * .0975) + (int) round(5998 * .0975), $r['totals']['tax_cents']);
    assert_same(11498 + 2998 + $r['totals']['tax_cents'], $r['totals']['total_cents']);
});

test('pricing: free shipping from $150 per package, defaults to cheapest', function () use ($rules, $line) {
    $r = (new Pricing($rules))->calculate([$line(1, 15000)]);
    assert_same('free_shipping', $r['packages'][0]['shipping_method']);
    assert_same(0, $r['totals']['shipping_cents']);
    $chosen = (new Pricing($rules))->calculate([$line(1, 15000)], [], [1 => 'flat_rate']);
    assert_same(1499, $chosen['totals']['shipping_cents'], 'customer may still pick flat rate');
    $under = (new Pricing($rules))->calculate([$line(1, 14999)], [], [1 => 'free_shipping']);
    assert_same('flat_rate', $under['packages'][0]['shipping_method'], 'free shipping not offered below minimum');
});

test('pricing: shipping class costs are charged once per class', function () use ($rules, $line) {
    $r = (new Pricing($rules))->calculate([
        $line(1, 1000, 1, ['shipping_class' => 'american-wigs']),
        $line(1, 1000, 1, ['shipping_class' => 'american-wigs']),
        $line(1, 1000),
    ]);
    assert_same(2999 + 1499, $r['totals']['shipping_cents']);
});

test('pricing: percent coupon, commission and vendor earning (shared cost)', function () use ($rules, $line, $coupon) {
    $r = (new Pricing($rules))->calculate([$line(1, 10000)], [$coupon(['amount' => 10])]);
    $p = $r['packages'][0];
    assert_same(1000, $p['discount_cents']);
    assert_same((int) round(9000 * .0975), $p['tax_cents']);
    assert_same(1350, $p['commission_cents'], '15% of the discounted 90.00');
    assert_same(9000 - 1350 + 1499 + $p['tax_cents'], $p['vendor_earning_cents']);
});

test('pricing: vendor coupon applies only to that vendor and the vendor pays for it', function () use ($rules, $line, $coupon) {
    $r = (new Pricing($rules))->calculate([$line(1, 10000), $line(2, 10000)], [$coupon(['vendor_id' => 2, 'amount' => 25])]);
    [$a, $b] = $r['packages'];
    assert_same(0, $a['discount_cents']);
    assert_same(2500, $b['discount_cents']);
    assert_same(1500, $b['commission_cents'], 'commission on the full price');
    assert_same(10000 - 1500 - 2500 + 1499 + $b['tax_cents'], $b['vendor_earning_cents']);
});

test('pricing: platform-funded coupon leaves vendor earning untouched', function () use ($rules, $line, $coupon) {
    $r = (new Pricing($rules))->calculate([$line(1, 10000)], [$coupon(['cost_bearer' => 'platform', 'amount' => 20])]);
    $p = $r['packages'][0];
    assert_same(2000, $p['discount_cents']);
    assert_same(1500, $p['commission_cents']);
    assert_same(10000 - 1500 + 1499 + $p['tax_cents'], $p['vendor_earning_cents']);
});

test('pricing: fixed cart coupon is spread proportionally and never exceeds the cart', function () use ($rules, $line, $coupon) {
    $r = (new Pricing($rules))->calculate([$line(1, 3000), $line(1, 1000)], [$coupon(['type' => 'fixed_cart', 'amount' => 20])]);
    $lines = $r['packages'][0]['lines'];
    assert_same(1500, $lines[0]['discount_cents']);
    assert_same(500, $lines[1]['discount_cents']);
    $big = (new Pricing($rules))->calculate([$line(1, 3000)], [$coupon(['type' => 'fixed_cart', 'amount' => 150])]);
    assert_same(3000, $big['totals']['discount_cents']);
    assert_same(0, $big['totals']['tax_cents']);
});

test('pricing: sale items excluded and free-shipping coupon', function () use ($rules, $line, $coupon) {
    $r = (new Pricing($rules))->calculate([$line(1, 5000, 1, ['on_sale' => true]), $line(1, 5000)], [$coupon(['exclude_sale_items' => 1, 'amount' => 10, 'free_shipping' => 1])]);
    assert_same(500, $r['totals']['discount_cents']);
    assert_same(0, $r['totals']['shipping_cents']);
});
