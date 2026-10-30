<?php
/**
 * Windowed pagination: Previous 1 2 3 … 7 Next. Keeps extra query params (e.g. sort).
 * Vars: $base (e.g. "/shop/"), $current, $pages, $query (array)
 */
if ($pages <= 1) {
    return;
}
$query ??= [];
$qs = $query ? '?' . http_build_query($query) : '';
$href = static fn (int $n) => link_to(($n === 1 ? $base : $base . 'page/' . $n . '/')) . $qs;
$show = array_unique(array_filter([1, 2, 3, $current - 1, $current, $current + 1, $pages], static fn ($n) => $n >= 1 && $n <= $pages && ($n <= 3 ? $current <= 3 || $n === 1 : true)));
sort($show);
?>
<nav class="pagination" aria-label="Pages">
    <?php if ($current > 1): ?><a href="<?= e($href($current - 1)) ?>" rel="prev">Previous</a><?php endif ?>
    <?php $prev = 0; foreach ($show as $n): ?>
        <?php if ($n - $prev > 1): ?><span class="pagination__gap">…</span><?php endif ?>
        <?php if ($n === $current): ?><span aria-current="page"><?= $n ?></span><?php else: ?><a href="<?= e($href($n)) ?>"><?= $n ?></a><?php endif ?>
    <?php $prev = $n; endforeach ?>
    <?php if ($current < $pages): ?><a href="<?= e($href($current + 1)) ?>" rel="next">Next</a><?php endif ?>
</nav>
