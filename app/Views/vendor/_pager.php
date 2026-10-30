<?php
/** @var int $page @var int $pages @var string $base e.g. /dashboard/orders/ ; $query keeps filters */
if ($pages <= 1) {
    return;
}
$query = isset($query) && $query !== [] ? '?' . http_build_query($query) : '';
$link = static fn (int $n) => link_to($base . ($n > 1 ? 'page/' . $n . '/' : '')) . $query;
?>
<nav class="pager" aria-label="Pages">
    <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>" aria-label="Previous page">‹</a><?php endif ?>
    <?php for ($n = max(1, $page - 2); $n <= min($pages, $page + 2); $n++): ?>
        <?php if ($n === $page): ?><span aria-current="page"><?= $n ?></span><?php else: ?><a href="<?= e($link($n)) ?>"><?= $n ?></a><?php endif ?>
    <?php endfor ?>
    <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>" aria-label="Next page">›</a><?php endif ?>
</nav>
