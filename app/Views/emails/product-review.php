<?php $this->layout('emails/layout', ['heading' => $approved ? 'Your product is live' : 'Changes needed']) ?>
<?php if ($approved): ?>
<p><strong><?= e($product['name']) ?></strong> has been approved and is now visible in the shop.</p>
<p><a href="<?= e(url('/product/' . $product['slug'] . '/')) ?>" style="color:#C3A36A">View product</a></p>
<?php else: ?>
<p>We reviewed <strong><?= e($product['name']) ?></strong> and it needs a few changes before it can go live:</p>
<p style="padding:10px 12px;background:#F7EED9;border-radius:6px"><?= nl2br(e($note)) ?></p>
<p><a href="<?= e(url('/dashboard/products/' . $product['id'] . '/')) ?>" style="color:#C3A36A">Edit the product</a></p>
<?php endif ?>
