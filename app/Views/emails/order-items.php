<?php /** Items table shared by order emails. */ ?>
<table role="presentation" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0">
    <tr style="background:#F6ECE3"><th align="left">Product</th><th align="center">Qty</th><th align="right">Price</th></tr>
    <?php foreach ($items as $i): ?>
        <tr style="border-bottom:1px solid #eee">
            <td><?= e($i['name']) ?><?php if ($i['variant_label']): ?><br><small style="color:#777"><?= e($i['variant_label']) ?></small><?php endif ?><?php if (!empty($showVendor)): ?><br><small style="color:#777">Sold by <?= e($i['vendor_name']) ?></small><?php endif ?></td>
            <td align="center"><?= (int) $i['quantity'] ?></td>
            <td align="right"><?= e(money((int) $i['subtotal_cents'])) ?></td>
        </tr>
    <?php endforeach ?>
</table>
