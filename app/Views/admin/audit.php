<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$subjectLink = static fn (array $r) => match ($r['subject_type']) {
    'vendor' => '/admin/vendors/' . $r['subject_id'] . '/',
    'order' => '/admin/orders/' . $r['subject_id'] . '/',
    'product' => '/admin/products/' . $r['subject_id'] . '/',
    'user' => '/admin/customers/' . $r['subject_id'] . '/',
    'payout_request' => '/admin/payouts/' . $r['subject_id'] . '/',
    'coupon' => '/admin/coupons/' . $r['subject_id'] . '/',
    default => null,
};
?>
<nav class="chips" aria-label="Filter">
    <a href="<?= e(link_to('/admin/audit/')) ?>"<?= $action === '' ? ' aria-current="page"' : '' ?>>Everything</a>
    <?php foreach ($groups as $g): ?><a href="<?= e(link_to('/admin/audit/') . '?action=' . rawurlencode($g)) ?>"<?= $action === $g ? ' aria-current="page"' : '' ?>><?= e(ucfirst($g)) ?></a><?php endforeach ?>
</nav>
<section class="card card--flush">
    <?php if (!$rows): ?><div class="empty"><?= picon('shield') ?>Nothing recorded yet.</div><?php else: ?>
    <div class="table-wrap"><table class="table table--stack">
        <thead><tr><th>When (UTC)</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $meta = json_decode((string) $r['meta'], true) ?: []; $link = $r['subject_id'] ? $subjectLink($r) : null; ?>
            <tr>
                <td data-label="When" class="nowrap small"><?= e($r['created_at']) ?></td>
                <td data-label="Who" class="small"><?= e((string) ($r['email'] ?? 'System')) ?><?= isset($meta['impersonated_by']) ? '<br><span class="muted">as vendor, by admin #' . (int) $meta['impersonated_by'] . '</span>' : '' ?></td>
                <td data-label="Action"><code class="small"><?= e($r['action']) ?></code></td>
                <td data-label="Record" class="small"><?php if ($r['subject_type']): ?><?= $link ? '<a href="' . e(link_to($link)) . '">' : '' ?><?= e($r['subject_type'] . ($r['subject_id'] ? ' #' . $r['subject_id'] : '')) ?><?= $link ? '</a>' : '' ?><?php endif ?></td>
                <td data-label="Details" class="small audit-meta"><?php unset($meta['impersonated_by']); ?><?= $meta ? e(mb_substr_safe(json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 220)) : '' ?></td>
                <td data-label="IP" class="small muted"><?= e((string) $r['ip']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?= $this->insert('vendor/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/audit/', 'query' => array_filter(['action' => $action])]) ?>
    <?php endif ?>
</section>
