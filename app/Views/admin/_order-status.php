<?php $labels = ['pending' => 'Pending payment', 'processing' => 'Processing', 'on-hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed', 'shipped' => 'Shipped']; ?>
<span class="pill pill--<?= e($status) ?>"><?= e($labels[$status] ?? ucfirst($status)) ?></span>
