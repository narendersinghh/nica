<?php $this->layout('account/layout') ?>
<?php if (!$orders): ?>
    <div class="notice">No order has been made yet. <a href="<?= e(link_to('/shop/')) ?>">Browse products</a></div>
<?php else: ?>
    <?= $this->insert('account/order-table', ['orders' => $orders]) ?>
    <?= $this->insert('partials/pagination', ['base' => '/my-account/orders/', 'current' => $page, 'pages' => $pages]) ?>
<?php endif ?>
