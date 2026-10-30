<?php
/** @var App\Core\Template $this */
use App\Http\Controllers\Admin\ReviewsController;
$this->layout('layouts/panel');
$stars = static fn (int $n) => str_repeat('★', $n) . str_repeat('☆', 5 - $n);
$btn = static fn (array $r, string $action, string $label, string $class = 'btn btn--ghost btn--sm') => '<form class="inline-form" method="post" action="' . e(link_to('/admin/reviews/' . $r['id'] . '/' . $action)) . '"' . ($action === 'delete' ? ' data-confirm="Delete this review for good?"' : '') . '>' . csrf_field() . '<input type="hidden" name="return_status" value="' . e($r['status']) . '"><button class="' . $class . '" type="submit">' . e($label) . '</button></form>';
?>
<nav class="chips" aria-label="Filter reviews">
    <?php foreach (ReviewsController::STATUSES as $k => $l): ?><a href="<?= e(link_to('/admin/reviews/') . '?status=' . $k) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><span><?= (int) $counts[$k] ?></span></a><?php endforeach ?>
</nav>
<section class="card card--flush">
    <?php if (!$rows): ?><div class="empty"><?= picon('star') ?><?= $status === 'pending' ? 'All caught up: no reviews waiting.' : 'No reviews here.' ?></div><?php endif ?>
    <?php foreach ($rows as $r): ?>
        <article class="review">
            <div class="toolbar">
                <div><span class="stars"><?= $stars((int) $r['rating']) ?></span> <strong><?= e($r['author_name']) ?></strong> <span class="muted small"><?= e((string) $r['author_email']) ?></span><?= (int) $r['is_verified_buyer'] ? ' <span class="pill pill--published">Verified buyer</span>' : '' ?><?= (int) $r['is_featured'] ? ' <span class="pill pill--pending">Featured</span>' : '' ?></div>
                <span class="muted small"><?= e(gmdate('M j, Y', strtotime($r['created_at']))) ?> · <a href="<?= e(link_to('/product/' . $r['product_slug'] . '/')) ?>" target="_blank" rel="noopener"><?= e($r['product_name']) ?></a> · <?= e($r['store_name']) ?></span>
            </div>
            <p><?= nl2br(e($r['body'])) ?></p>
            <?php if ($r['vendor_reply']): ?><div class="reply small"><strong>Vendor reply:</strong> <?= nl2br(e($r['vendor_reply'])) ?></div><?php endif ?>
            <div class="toolbar toolbar--start">
                <?php if ($r['status'] !== 'approved'): ?><?= $btn($r, 'approve', 'Publish', 'btn btn--sm') ?><?php endif ?>
                <?php if ($r['status'] === 'approved'): ?><?= $btn($r, (int) $r['is_featured'] ? 'unfeature' : 'feature', (int) $r['is_featured'] ? 'Remove from home page' : 'Feature on home page') ?><?php endif ?>
                <?php if ($r['status'] !== 'rejected'): ?><?= $btn($r, 'reject', 'Hide') ?><?php endif ?>
                <?php if ($r['status'] !== 'spam'): ?><?= $btn($r, 'spam', 'Spam') ?><?php endif ?>
                <?= $btn($r, 'delete', 'Delete', 'btn btn--danger btn--sm') ?>
            </div>
        </article>
    <?php endforeach ?>
    <?= $this->insert('vendor/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/reviews/', 'query' => ['status' => $status]]) ?>
</section>
