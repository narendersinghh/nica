<?php

// Commerce rules, taken from the live WooCommerce/Dokan configuration.
// Values can be overridden from the admin panel (stored in settings, key "commerce").
return [
    'currency' => 'USD',
    'allowed_countries' => ['US'],

    // Sales tax: one rate everywhere, products only (shipping is not taxed), prices exclude tax.
    'tax' => ['enabled' => true, 'rate' => 9.75, 'label' => 'Tax', 'on_shipping' => false],

    // Shipping is calculated per vendor package (Dokan splits the cart by vendor).
    // Flat rate "per class": each shipping class in the package is charged once.
    'shipping' => [
        'flat_rate' => [
            'title' => 'Flat rate',
            'no_class_cents' => 1499,
            'class_cents' => ['american-wigs' => 2999],
        ],
        'free_shipping' => ['title' => 'Free shipping', 'min_cents' => 15000],
    ],

    // Marketplace money.
    'commission_rate' => 15.0,               // admin percentage per vendor sale
    'shipping_fee_recipient' => 'vendor',
    'tax_fee_recipient' => 'vendor',
    'payout_threshold_cents' => 5000,        // minimum balance before a manual payout can be requested
    'stock_hold_minutes' => 60,
    'order_number_start' => 6000,
];
