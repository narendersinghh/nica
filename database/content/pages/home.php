<?php

// Homepage sections (from the live Elementor page). Stored in pages.data on import.

return [
    'hero' => [
        'eyebrow' => 'The Trusted hair marketplace',
        'lines' => ['Discover Hair.', 'Discover Beauty.'],
        'highlight' => 'Discover NiCa.',
        'text' => 'The first hair marketplace powered by The NiCa Score™ - testing vendors across multiple installs so you can shop with 100% confidence.',
        'primary' => ['label' => 'Shop Premium Hair', 'url' => '/shop/'],
        'secondary' => ['label' => 'Find My Match', 'url' => '/find-match/'],
        'video' => '2026/07/NCC-TV15.mp4',
        'background' => '2026/07/banner1.webp',
        'image' => '2026/07/right-side-image.webp',
        'features' => [
            ['icon' => 'shield', 'title' => 'Verified Vendors', 'text' => 'Carefully reviewed for quality and reliability.'],
            ['icon' => 'diamond', 'title' => 'NiCa Score™ Verified', 'text' => 'Only premium hair that meets our standards.'],
            ['icon' => 'book', 'title' => 'Real Education', 'text' => 'Honest guidance so you can buy with confidence.'],
        ],
        'news' => [
            'label' => 'Read News Story >',
            'url' => 'https://newschannel9.com/this-n-that/be-bold-be-beautiful-be-nica',
            'logo' => '2026/07/wtvc-logo.webp',
        ],
    ],
    'marquee' => ['Verified Vendors', 'Buyer Protection', 'Smart Matching', 'Premium Quality', 'Global Shipping', 'Secure Payments'],
    'hair_types' => ['eyebrow' => 'Shop by Hair Type', 'title' => 'Your texture, beautifully matched'],
    'products' => [
        'eyebrow' => 'Community Favorites',
        'title' => 'Most-loved Products',
        'button' => ['label' => 'Explore All Products', 'url' => '/shop/'],
    ],
    'match' => [
        'eyebrow' => 'See it. Feel it. Match it.',
        'title' => 'Find your perfect match',
        'text' => 'Tell us your type, length, colour, and texture, and our smart matching surfaces will vet vendor products made for you. No more wasted bundles.',
        'bullets' => ['Personalised across verified vendors', 'Backed by buyer protection', 'Save your match & shop anytime'],
        'button' => ['label' => 'Start the Match Quiz', 'url' => '/find-match/'],
        'steps' => [
            ['title' => 'Tell us about you', 'text' => 'Texture, length, colour & the look you love.'],
            ['title' => 'We match the vendors', 'text' => 'Smart filtering across verified, quality-checked brands.'],
            ['title' => 'Shop with confidence', 'text' => 'Buyer protection on every purchase, start to finish.'],
        ],
    ],
    'before_after' => [
        'eyebrow' => 'Before & After',
        'title' => 'The transformation is real',
        'slides' => [
            ['image' => '2026/06/1.webp', 'caption' => 'Bundles Galore using brazilian body wave bundles'],
            ['image' => '2026/06/2.webp', 'caption' => 'The NiCa Collection U-Part wig Indian body wave'],
            ['image' => '2026/06/3.webp', 'caption' => 'Bundles Galore Brazilian straight wig'],
            ['image' => '2026/07/comparison4.webp', 'caption' => 'YGE Cambodian Deep Wave'],
        ],
    ],
    'testimonials' => [
        'eyebrow' => 'Testimonials',
        'title' => 'Love hearing from you',
        'text' => 'We value every voice in our growing community and celebrate the stories behind every transformation.',
        'count' => 3,
        'button' => ['label' => 'Read Testimonials', 'url' => '/testimonials/'],
    ],
    'journal' => [
        'eyebrow' => 'The NiCa Journal',
        'title' => 'Empowerment through knowledge',
        'count' => 4,
        'button' => ['label' => 'Read More Blog', 'url' => '/blog/'],
    ],
    'why' => [
        'eyebrow' => 'The Big Problem We Solve',
        'title' => 'Why NiCa exists',
        'before' => ['title' => 'Before NiCa', 'items' => ['Endless searching', 'Uncertain quality', 'No centralized source', 'Risk of poor hair purchases', 'Vendors struggling to reach the right buyers']],
        'after' => ['title' => 'With NiCa', 'items' => ['Everything in one place', 'Only vetted, quality-focused vendors', 'Convenient product comparisons', 'Clear data, trends & insights', 'A trusted marketplace you can rely on']],
    ],
    'audience' => [
        'eyebrow' => 'Built for everyone in beauty',
        'title' => 'Wherever you are, we meet you there',
        'cards' => [
            ['image' => '2026/02/bg-1.webp', 'title' => 'For Vendors', 'text' => 'Reach buyers searching for premium hair. Zero upfront fees, commission only. Founding vendors lock in lifetime Premiere status.', 'cta' => 'Become a Vendor', 'url' => '/for-vendors/'],
            ['image' => '2026/06/Bundles-Do-You-Need-For-A-Full-Head.webp', 'title' => 'For Stylists', 'text' => 'Turn expertise into income. Refer NiCa, your clients shop quality, you earn commission on every sale.', 'cta' => 'Refer & Earn', 'url' => '/for-stylists/'],
            ['image' => '2026/04/hair-stylist.webp', 'title' => 'For Customers', 'text' => 'Smart matching, curated selection, buyer protection and premium quality, backed by expert support.', 'cta' => 'Explore Marketplace', 'url' => '/for-customers/'],
        ],
    ],
];
