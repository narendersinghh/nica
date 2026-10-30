<?php $this->layout('emails/layout', ['heading' => 'Your order is on its way']) ?>
<p>Good news: <?= e($store) ?> has shipped your items from order #<?= e($order['number']) ?>.</p>
<p><strong>Carrier:</strong> <?= e($vo['tracking_carrier']) ?><br><strong>Tracking number:</strong> <?= e($vo['tracking_number']) ?></p>
<?php if ($vo['tracking_url']): ?><p><a href="<?= e($vo['tracking_url']) ?>" style="color:#C3A36A">Track your package</a></p><?php endif ?>
<?= $this->insert('emails/order-items', ['items' => $items]) ?>
<?php if ($order['user_id']): ?><p><a href="<?= e(url('/my-account/view-order/' . $order['number'] . '/')) ?>" style="color:#C3A36A">View your order</a></p><?php endif ?>
