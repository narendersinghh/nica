<?php /** Status pill. $status, optional $label */ ?>
<span class="pill pill--<?= e($status) ?>"><?= e($label ?? (App\Services\Vendor\VendorOrders::STATUS_LABELS[$status] ?? ucfirst($status))) ?></span>
