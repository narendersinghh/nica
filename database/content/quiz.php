<?php

// Find Match quiz, as configured on the live site (hf3-app.js). IDs here are
// WordPress term IDs; the importer maps them to the new catalog and stores the
// result in settings('quiz'). Matching logic: "andf" = must match every chosen
// filter, falling back to the closest matches when nothing matches all.

return [
    'logic' => 'andf',
    'steps' => [
        [
            'key' => 'cat', 'source' => 'category', 'required' => true,
            'question' => 'What are you looking for?',
            'subtitle' => 'Start with the pieces you have in mind. Pick as many as you like.',
            'options' => [
                ['legacy' => 51, 'name' => 'Bulk Hair'],
                ['legacy' => 69, 'name' => 'Closures'],
                ['legacy' => 52, 'name' => 'Crochet', 'children' => [101]],
                ['legacy' => 49, 'name' => 'Hair Extensions', 'children' => [104, 103, 92, 90, 89, 111, 112]],
                ['legacy' => 110, 'name' => 'Human Braid Hair'],
                ['legacy' => 109, 'name' => 'Human Hair Toppers', 'image' => '2026/07/banner1.webp'],
                ['legacy' => 21, 'name' => 'Wigs'],
            ],
        ],
        [
            'key' => 'tex', 'source' => 'tag',
            'question' => 'Which texture is your match?',
            'subtitle' => 'Choose the hair textures you already have, or the look you want to create.',
            'options' => [
                ['legacy' => 84, 'name' => 'Curly'],
                ['legacy' => 93, 'name' => 'Kinky'],
                ['legacy' => 82, 'name' => 'Straight'],
                ['legacy' => 83, 'name' => 'Wavy'],
            ],
        ],
        [
            'key' => 'color', 'source' => 'attribute', 'display' => 'swatch',
            'question' => 'What shade are you after?',
            'subtitle' => 'Pick one or more colours. Still deciding? Skip this step.',
            'options' => [
                ['legacy' => [86], 'name' => 'Black', 'swatch' => '#111111'],
                ['legacy' => [88], 'name' => 'Blonde', 'swatch' => '#d9bd7f'],
                ['legacy' => [95], 'name' => 'Brown', 'swatch' => '#5c3a21'],
                ['legacy' => [87], 'name' => 'Gray', 'swatch' => '#9a9a9a'],
                ['legacy' => [94], 'name' => 'Red', 'swatch' => '#a41f1f'],
            ],
        ],
        [
            'key' => 'length', 'source' => 'attribute', 'display' => 'text',
            'question' => 'How long do you want to go?',
            'subtitle' => 'Pick the lengths that suit you. Skip this step if you are flexible.',
            'options' => [
                ['legacy' => [55], 'name' => 'Short', 'sub' => 'Up to 10 inches'],
                ['legacy' => [56, 58], 'name' => 'Shoulder', 'sub' => '12 to 14 inches'],
                ['legacy' => [57, 65, 59], 'name' => 'Medium', 'sub' => '16 to 20 inches'],
                ['legacy' => [60, 61, 62, 63, 64, 68], 'name' => 'Long', 'sub' => '22 inches and above'],
            ],
        ],
    ],
];
