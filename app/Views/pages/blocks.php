<?php
/**
 * Designed pages are an ordered list of blocks (pages.data.blocks). Each block
 * type has a partial in views/blocks/. Unknown types are skipped.
 * @var App\Core\Template $this
 */
$this->layout('layouts/site', ['bodyClass' => 'page-' . e($page['slug'])]);
foreach ($data['blocks'] ?? [] as $i => $block) {
    $type = (string) ($block['type'] ?? '');
    if (!preg_match('/^[a-z_]+$/', $type) || !is_file(app()->path('app/Views/blocks/' . $type . '.php'))) {
        continue;
    }
    echo $this->insert('blocks/' . $type, ['b' => $block, 'page' => $page, 'blockIndex' => $i]);
    if ($type === 'quiz') {
        $this->start('scripts');
        echo '<script src="' . e(asset('assets/js/quiz.js')) . '" defer></script>';
        $this->stop();
    }
}
