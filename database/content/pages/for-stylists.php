<?php

return ['blocks' => [
    ['type' => 'banner', 'eyebrow' => 'Refer and Earn', 'icon' => 'money',
        'text' => 'Stylists become trusted guides, connecting clients to quality products while earning commissions.'],
    ['type' => 'emoji_cards', 'columns' => 4,
        'cards' => [
            ['emoji' => '💬', 'title' => 'Client asks for hair guidance', 'text' => 'A customer consults their stylist for recommendations.'],
            ['emoji' => '🤝', 'title' => 'Stylist refers NiCa', 'text' => 'The stylist introduces The NiCa Collection and explains the process.'],
            ['emoji' => '🛒', 'title' => 'Customer shops & purchases', 'text' => 'The client selects and buys directly through the NiCa platform.'],
            ['emoji' => '💰', 'title' => 'Vendor sells, stylist earns', 'text' => 'Vendors receive the sale, and stylists earn commissions based on their tier.'],
        ],
        'title' => 'Turn Expertise Into Opportunity',
        'button' => ['label' => 'Refer & Earn With NiCa', 'url' => '/my-account/stylist/', 'icon' => 'money']],
]];
