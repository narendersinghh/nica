<?php /** Products block inside blog posts. */ ?>
<div class="related-products">
    <div class="product-grid product-grid--3">
        <?php foreach ($products as $product): ?><?= $this->insert('partials/product-card', ['product' => $product]) ?><?php endforeach ?>
    </div>
</div>
