<?php /** @var App\Core\Template $this */ $this->layout('layouts/base', ['bodyClass' => 'phase0', 'robots' => 'noindex, nofollow']) ?>

<main class="phase0__wrap phase0__wrap--wide">
    <p class="eyebrow">Local only</p>
    <h1 class="display display--sm">Environment status</h1>
    <p class="phase0__meta">Base URL <strong><?= e($baseUrl) ?></strong> · host <strong><?= e($host) ?></strong> · scheme <strong><?= e($scheme) ?></strong></p>

    <table class="checks">
        <tbody>
        <?php foreach ($checks as [$label, $ok, $note]): ?>
            <tr class="<?= $ok ? 'is-ok' : 'is-bad' ?>">
                <td class="checks__icon" aria-label="<?= $ok ? 'OK' : 'Missing' ?>"><?= $ok ? '✓' : '✕' ?></td>
                <td><?= e($label) ?></td>
                <td class="checks__note"><?= e($note) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</main>
