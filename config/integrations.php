<?php

// Third-party services. All are OFF until an ID/key is entered in Admin → Settings.
// Tracking scripts load only after the visitor accepts cookies, and never block rendering.
return [
    'consent_required' => true,
    'ga4' => ['measurement_id' => ''],                     // G-XXXXXXX
    'meta_pixel' => ['pixel_id' => ''],                     // Meta Pixel (browser). Conversions API: token goes in .env (META_CAPI_TOKEN)
    'klaviyo' => ['public_key' => ''],                      // site ID / public API key (6 characters)
    'google_merchant' => ['enabled' => false, 'google_category' => 'Health & Beauty > Personal Care > Hair Care > Hair Extensions'],
    'shipstation' => ['enabled' => false],                  // order export endpoint (credentials in .env when connected)
];
