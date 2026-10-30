<?php

// Stylist referral program (new; the old site only advertised it).
// Defaults chosen for launch; all editable in Admin → Settings.
return [
    'enabled' => true,
    'cookie_days' => 30,               // a click on a stylist's link counts for orders within 30 days (last click wins)
    'auto_approve' => false,           // new stylists wait for an admin, like vendors
    'payout_threshold_cents' => 2500,  // stylists can request a payout from $25
    'tier_window_days' => 90,          // tiers follow referred sales over the last 90 days
    // Commission on referred item sales (after discounts, before tax and shipping).
    // Paid by the marketplace out of its own commission, never by the vendor.
    'tiers' => [
        ['key' => 'bronze', 'name' => 'Bronze', 'min_cents' => 0, 'rate' => 5.0],
        ['key' => 'silver', 'name' => 'Silver', 'min_cents' => 100000, 'rate' => 7.5],
        ['key' => 'gold', 'name' => 'Gold', 'min_cents' => 500000, 'rate' => 10.0],
    ],
];
