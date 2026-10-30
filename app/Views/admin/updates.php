<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$short = static fn (?string $sha) => $sha ? substr($sha, 0, 7) : '—';
$when = static fn (?string $iso) => $iso ? gmdate('M j, Y g:ia', strtotime($iso)) . ' UTC' : '—';
$firstLine = static fn (string $msg) => strtok($msg, "\n") ?: $msg;
$current = $installed['sha'] ?? null;
$release = $remote['release'] ?? null;
$head = $remote['head'] ?? null;
$canInstall = !$problems && $remote !== null;
?>
<?php $this->start('actions') ?>
<a class="btn btn--ghost" href="<?= e(link_to('/admin/updates/') . '?refresh=1') ?>"><?= picon('refund') ?>Check again</a>
<?php $this->stop() ?>

<?php foreach ($problems as $p): ?>
    <div class="alert alert--warn"><?= picon('alert') ?><div><?= e($p) ?></div></div>
<?php endforeach ?>
<?php if ($error): ?>
    <div class="alert alert--error"><?= picon('alert') ?><div><?= e($error) ?></div></div>
<?php endif ?>
<?php if (!empty($installed['pending_finish'])): ?>
    <div class="alert alert--warn"><?= picon('alert') ?><div>The last update was copied but not finished.
        <form class="inline-form" method="post" action="<?= e(link_to('/admin/updates/finish')) ?>"><?= csrf_field() ?><button class="link-btn" type="submit">Finish it now</button></form></div></div>
<?php endif ?>

<div class="stats">
    <div class="stat stat--accent"><div class="stat__label">Installed</div><div class="stat__value"><?= e($installed['ref'] ?? 'Initial upload') ?></div>
        <div class="stat__meta"><?= $current ? e($short($current)) . ' · ' . e($when($installed['installed_at'] ?? null)) : 'Not installed through this page yet' ?></div></div>
    <div class="stat"><div class="stat__label">Latest release</div><div class="stat__value"><?= e($release['tag'] ?? 'None yet') ?></div>
        <div class="stat__meta"><?= $release ? e($when($release['published_at'])) : 'Publish one on GitHub → Releases' ?></div></div>
    <div class="stat"><div class="stat__label">Latest commit on <?= e($branch) ?></div><div class="stat__value"><?= e($short($head['sha'] ?? null)) ?></div>
        <div class="stat__meta"><?= $head ? e($when($head['date'])) : '—' ?></div></div>
    <div class="stat"><div class="stat__label">Source</div><div class="stat__value small"><a href="https://github.com/<?= e($repository) ?>" target="_blank" rel="noopener noreferrer"><?= e($repository) ?></a></div>
        <div class="stat__meta">Checked <?= e($when($remote['checked_at'] ?? null)) ?></div></div>
</div>

<div class="grid grid--2">
    <?php foreach (['release' => $release, 'commit' => $head] as $kind => $target): ?>
    <section class="card">
        <div class="card__head">
            <h2><?= $kind === 'release' ? 'Update to the latest release' : 'Update to the latest commit' ?></h2>
            <?php if ($target && $current === $target['sha']): ?><span class="pill pill--published">Installed</span><?php endif ?>
        </div>
        <?php if (!$target): ?>
            <p class="muted small"><?= $kind === 'release' ? 'No release has been published yet. Releases are the safest way to ship: create one on GitHub when a version is ready.' : 'Could not read the branch.' ?></p>
        <?php else: ?>
            <p class="small"><strong><?= e($kind === 'release' ? $target['name'] : $firstLine($target['message'])) ?></strong><br>
                <span class="muted"><?= e($short($target['sha'])) ?> · <?= e($when($kind === 'release' ? $target['published_at'] : $target['date'])) ?> ·
                <a href="<?= e($target['url']) ?>" target="_blank" rel="noopener noreferrer">View on GitHub</a></span></p>
            <?php if ($kind === 'release' && trim($target['notes']) !== ''): ?><div class="release-notes small"><?= nl2br(e(mb_substr_safe($target['notes'], 1500))) ?></div><?php endif ?>
            <?php $c = $changes[$kind]; ?>
            <?php if ($c && $c['commits']): ?>
                <p class="small muted"><?= (int) $c['ahead'] ?> change<?= (int) $c['ahead'] === 1 ? '' : 's' ?> since the installed version:</p>
                <ul class="list-rows small commit-list">
                    <?php foreach (array_slice($c['commits'], 0, 12) as $commit): ?><li><span><?= e($firstLine($commit['message'])) ?></span><code><?= e($short($commit['sha'])) ?></code></li><?php endforeach ?>
                </ul>
                <?php if ((int) $c['behind'] > 0): ?><p class="small alert alert--warn">This is <?= (int) $c['behind'] ?> commit(s) behind the installed version: installing it goes back in time.</p><?php endif ?>
            <?php endif ?>
            <?php if ($current !== $target['sha']): ?>
            <form method="post" action="<?= e(link_to('/admin/updates/install')) ?>" data-confirm="Install <?= e($kind === 'release' ? $target['tag'] : $short($target['sha'])) ?>? The site shows a short maintenance page while the files are replaced (usually under a minute). A backup is made first.">
                <?= csrf_field() ?>
                <input type="hidden" name="target" value="<?= e($kind) ?>">
                <input type="hidden" name="sha" value="<?= e($target['sha']) ?>">
                <button class="btn<?= $kind === 'commit' ? ' btn--ghost' : '' ?>" type="submit"<?= $canInstall ? '' : ' disabled' ?>><?= picon('download') ?>Install <?= e($kind === 'release' ? $target['tag'] : $short($target['sha'])) ?></button>
            </form>
            <?php endif ?>
            <?php if ($kind === 'commit'): ?><p class="hint muted small">The latest commit may include unfinished work. Prefer releases on the live site.</p><?php endif ?>
        <?php endif ?>
    </section>
    <?php endforeach ?>
</div>

<section class="card">
    <div class="card__head"><h2>Backups and rollback</h2></div>
    <p class="small muted">Before every update the current code is saved (the last <?= (int) config('updates.keep_backups', 3) ?> are kept). Rolling back restores the code; the database and uploaded images are not touched. If the admin panel itself stops working, run <code>php bin/console update:rollback</code> on the server.</p>
    <?php if (!$backups): ?>
        <p class="small">No backups yet.</p>
    <?php else: ?>
        <ul class="list-rows small">
            <?php foreach ($backups as $i => $b): ?>
                <li><span><?= e(gmdate('M j, Y g:ia', $b['created'])) ?> UTC · <?= e($b['ref'] ?? 'unknown version') ?><?= !empty($b['replaced_by']) ? ' <span class="muted">(before ' . e($b['replaced_by']) . ')</span>' : '' ?></span><span class="muted"><?= e(number_format($b['size'] / 1048576, 1)) ?> MB</span></li>
            <?php endforeach ?>
        </ul>
        <form method="post" action="<?= e(link_to('/admin/updates/rollback')) ?>" data-confirm="Restore the code from the most recent backup?">
            <?= csrf_field() ?>
            <button class="btn btn--danger btn--sm" type="submit"<?= $problems ? ' disabled' : '' ?>>Roll back to <?= e($backups[0]['ref'] ?? 'the previous version') ?></button>
        </form>
    <?php endif ?>
</section>

<section class="card">
    <h2>Never changed by updates</h2>
    <p class="small muted">Your settings file (<code>.env</code>), uploaded images, orders and all other data, logs, sessions and caches. Hosting lines in <code>.htaccess</code> (such as Hostinger's PHP version) are kept.</p>
</section>
