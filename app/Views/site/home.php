<?php /** @var App\Core\Template $this */ $this->layout('layouts/base', ['bodyClass' => 'phase0']) ?>

<main class="phase0__wrap">
    <p class="eyebrow">The Trusted hair marketplace</p>
    <h1 class="display">Discover Hair.<br>Discover Beauty.<br>Discover NiCa.</h1>
    <p class="lead">The new platform is running. Phase 0 (framework, security and database layer) is in place; the storefront design arrives in Phase 1.</p>
    <p class="phase0__meta">Serving <strong><?= e(app()->url()->base()) ?></strong></p>
    <?php if (app()->isLocal()): ?>
        <a class="btn" href="<?= e(route('status')) ?>">Environment status</a>
    <?php endif ?>
</main>
