<?php
/** Find Match quiz shell; steps come from settings('quiz') and results from /api/match. */
$quiz = json_decode((string) app()->db()->scalar("SELECT value FROM settings WHERE name = 'quiz'"), true) ?: ['steps' => []];
$config = ['api' => link_to('/api/match'), 'steps' => []];
foreach ($quiz['steps'] as $step) {
    $config['steps'][] = [
        'key' => $step['key'],
        'required' => !empty($step['required']),
        'display' => $step['display'] ?? 'image',
        'question' => $step['question'],
        'subtitle' => $step['subtitle'],
        'options' => array_map(static fn (array $o) => [
            'name' => $o['name'],
            'sub' => $o['sub'] ?? null,
            'swatch' => $o['swatch'] ?? null,
            'image' => !empty($o['image']) ? image_url($o['image'], 'medium') : null,
            'children' => array_map(static fn ($c) => ['name' => $c['name']], $o['children'] ?? []),
        ], $step['options']),
    ];
}
?>
<section class="section-pad section--soft">
    <div class="container">
        <div class="quiz" data-quiz>
            <noscript><p>The Find Match quiz needs JavaScript. You can still <a href="<?= e(link_to('/shop/')) ?>">browse all products</a>.</p></noscript>
        </div>
        <script type="application/json" id="quiz-config" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
    </div>
</section>
