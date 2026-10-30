<?php
/** @var App\Core\Template $this */
$this->layout('layouts/panel');
$stars = static fn (int $n) => str_repeat('★', $n) . str_repeat('☆', 5 - $n);
?>
<div class="stats">
    <div class="stat"><div class="stat__label">Average rating</div><div class="stat__value"><?= e(number_format((float) $stats['avg'], 1)) ?> <span class="stars small"><?= $stars((int) round((float) $stats['avg'])) ?></span></div></div>
    <div class="stat"><div class="stat__label">Reviews</div><div class="stat__value"><?= (int) $stats['n'] ?></div></div>
    <div class="stat"><div class="stat__label">Waiting for your reply</div><div class="stat__value"><?= (int) $stats['unanswered'] ?></div></div>
</div>
<nav class="chips" aria-label="Filter reviews">
    <a href="<?= e(link_to('/dashboard/reviews/')) ?>"<?= $filter === 'all' ? ' aria-current="page"' : '' ?>>All</a>
    <a href="<?= e(link_to('/dashboard/reviews/?filter=unanswered')) ?>"<?= $filter === 'unanswered' ? ' aria-current="page"' : '' ?>>Not answered<span><?= (int) $stats['unanswered'] ?></span></a>
</nav>
<section class="card card--flush">
    <?php if (!$reviews): ?>
        <div class="empty"><?= picon('star') ?><?= $filter === 'unanswered' ? 'You have answered every review.' : 'No reviews yet.' ?></div>
    <?php endif ?>
    <?php foreach ($reviews as $r): ?>
        <article class="review" id="review-<?= (int) $r['id'] ?>">
            <div class="toolbar">
                <div><span class="stars" aria-label="<?= (int) $r['rating'] ?> out of 5"><?= $stars((int) $r['rating']) ?></span> <strong><?= e($r['author_name']) ?></strong><?= (int) $r['is_verified_buyer'] ? ' <span class="pill pill--published">Verified buyer</span>' : '' ?></div>
                <span class="muted small"><?= e(gmdate('M j, Y', strtotime($r['created_at']))) ?> · <a href="<?= e(link_to('/product/' . $r['product_slug'] . '/')) ?>" target="_blank" rel="noopener"><?= e($r['product_name']) ?></a></span>
            </div>
            <p><?= nl2br(e($r['body'])) ?></p>
            <?php if ($r['vendor_reply']): ?><div class="reply"><strong class="small">Your reply</strong><p class="small"><?= nl2br(e($r['vendor_reply'])) ?></p></div><?php endif ?>
            <details class="reply-form"<?= !$r['vendor_reply'] && $filter === 'unanswered' ? ' open' : '' ?>>
                <summary><?= $r['vendor_reply'] ? 'Edit reply' : 'Reply' ?></summary>
                <form method="post" action="<?= e(link_to('/dashboard/reviews/' . $r['id'] . '/reply')) ?>">
                    <?= csrf_field() ?>
                    <label class="sr-only" for="reply-<?= (int) $r['id'] ?>">Your reply</label>
                    <textarea id="reply-<?= (int) $r['id'] ?>" name="reply" maxlength="2000" placeholder="Thank the customer or answer their question. Replies are public."><?= e((string) $r['vendor_reply']) ?></textarea>
                    <p><button class="btn btn--sm" type="submit">Publish reply</button></p>
                </form>
            </details>
        </article>
    <?php endforeach ?>
    <?= $this->insert('vendor/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/dashboard/reviews/', 'query' => $filter === 'unanswered' ? ['filter' => 'unanswered'] : []]) ?>
</section>
