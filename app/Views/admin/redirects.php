<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$fe = static fn (string $k) => $errors[$k] ?? null;
?>
<div class="grid grid--main">
    <div>
        <section class="card">
            <h2>Add a redirect</h2>
            <form method="post" action="<?= e(link_to('/admin/redirects/')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <div class="fld<?= $fe('from_path') ? ' fld--error' : '' ?>"><label for="f-from">From (old address)</label><input type="text" id="f-from" name="from_path" value="<?= e(old('from_path', $prefill)) ?>" placeholder="/old-page/" required><?= $fe('from_path') ? '<p class="err">From ' . e($fe('from_path')) . '</p>' : '' ?></div>
                <div class="fld<?= $fe('to_url') ? ' fld--error' : '' ?>"><label for="f-to">To</label><input type="text" id="f-to" name="to_url" value="<?= e(old('to_url')) ?>" placeholder="/new-page/ or https://…"><?= $fe('to_url') ? '<p class="err">Target ' . e($fe('to_url')) . '</p>' : '' ?></div>
                <div class="fld"><label for="f-code">Type</label><select id="f-code" name="status_code"><option value="301">Permanent (301)</option><option value="302">Temporary (302)</option><option value="410">Gone for good (410)</option></select></div>
                <div class="fld"><span class="fld-label">&nbsp;</span><button class="btn" type="submit">Save redirect</button></div>
            </form>
        </section>
        <section class="card card--flush">
            <div class="card__head"><h2>Redirects</h2><form class="search" method="get" action="<?= e(link_to('/admin/redirects/')) ?>" role="search"><?= picon('search') ?><label class="sr-only" for="rs">Search redirects</label><input id="rs" type="search" name="s" value="<?= e($search) ?>" placeholder="Search"></form></div>
            <div class="table-wrap"><table class="table table--stack">
                <thead><tr><th>From</th><th>To</th><th>Type</th><th class="right">Hits</th><th><span class="sr-only">Remove</span></th></tr></thead>
                <tbody>
                <?php foreach ($redirects as $r): ?>
                    <tr>
                        <td class="prod-col"><code><?= e($r['from_path']) ?></code></td>
                        <td data-label="To" class="small"><?= (int) $r['status_code'] === 410 ? '<span class="muted">Gone</span>' : e($r['to_url']) ?></td>
                        <td data-label="Type"><?= (int) $r['status_code'] ?></td>
                        <td data-label="Hits" class="right num"><?= (int) $r['hits'] ?></td>
                        <td class="right"><form class="inline-form" method="post" action="<?= e(link_to('/admin/redirects/' . $r['id'] . '/delete')) ?>" data-confirm="Remove this redirect?"><?= csrf_field() ?><button class="link-btn link-btn--danger small" type="submit">Remove</button></form></td>
                    </tr>
                <?php endforeach ?>
                <?php if (!$redirects): ?><tr><td colspan="5" class="empty">No redirects.</td></tr><?php endif ?>
                </tbody>
            </table></div>
        </section>
    </div>
    <section class="card" id="not-found">
        <div class="card__head"><h2>Pages not found</h2><?php if ($misses): ?><form method="post" action="<?= e(link_to('/admin/redirects/dismiss')) ?>" data-confirm="Clear the whole 404 log?"><?= csrf_field() ?><input type="hidden" name="path" value="*"><button class="link-btn small" type="submit">Clear all</button></form><?php endif ?></div>
        <p class="small muted">Addresses visitors tried that do not exist. Redirect the useful ones.</p>
        <ul class="list-rows">
            <?php foreach ($misses as $m): ?>
                <li><span><code class="small"><?= e($m['path']) ?></code><br><span class="muted small"><?= (int) $m['hits'] ?> visits · last <?= e(gmdate('M j', strtotime($m['last_seen_at']))) ?></span></span>
                    <span class="nowrap"><a class="small" href="<?= e(link_to('/admin/redirects/') . '?from=' . rawurlencode($m['path'])) ?>">Redirect</a> · <form class="inline-form" method="post" action="<?= e(link_to('/admin/redirects/dismiss')) ?>"><?= csrf_field() ?><input type="hidden" name="path" value="<?= e($m['path']) ?>"><button class="link-btn small" type="submit">Dismiss</button></form></span></li>
            <?php endforeach ?>
            <?php if (!$misses): ?><li class="muted small">Nothing logged.</li><?php endif ?>
        </ul>
    </section>
</div>
