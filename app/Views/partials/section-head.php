<?php
/** Eyebrow + heading (+ intro) used at the top of most sections. */
$align ??= 'center';
$tag ??= 'h2';
$eyebrowIcon ??= 'peace';
?>
<div class="section-head section-head--<?= e($align) ?>">
    <?php if (!empty($eyebrow)): ?><p class="eyebrow"><?= icon($eyebrowIcon) ?><span><?= e($eyebrow) ?></span></p><?php endif ?>
    <<?= $tag ?> class="section-head__title"><?= e($heading) ?></<?= $tag ?>>
    <?php if (!empty($intro)): ?><p class="section-head__intro"><?= e($intro) ?></p><?php endif ?>
</div>
