<?php
/** Price display: range for variable products, struck-through regular price when on sale. */
$min = (int) $product['price_min_cents'];
$max = (int) $product['price_max_cents'];
$regMin = (int) $product['regular_min_cents'];
?>
<span class="price">
<?php if ($max > $min): ?>
    <span class="price__amount"><?= e(money($min)) ?> – <?= e(money($max)) ?></span>
<?php elseif ((int) $product['on_sale'] && $regMin > $min): ?>
    <del class="price__regular" aria-label="Original price"><?= e(money($regMin)) ?></del>
    <ins class="price__sale" aria-label="Current price"><?= e(money($min)) ?></ins>
<?php else: ?>
    <span class="price__amount"><?= e(money($min)) ?></span>
<?php endif ?>
</span>
