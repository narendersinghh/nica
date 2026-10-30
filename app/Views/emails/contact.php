<?php $this->layout('emails/layout', ['heading' => 'New contact message']) ?>
<table role="presentation" cellpadding="6" cellspacing="0" style="width:100%;font-size:15px">
    <tr><td style="color:#777;width:110px">I am a</td><td><?= e(ucfirst($data['role'])) ?></td></tr>
    <tr><td style="color:#777">Name</td><td><?= e($data['name']) ?></td></tr>
    <tr><td style="color:#777">Email</td><td><a href="mailto:<?= e($data['email']) ?>"><?= e($data['email']) ?></a></td></tr>
    <?php if (!empty($data['phone'])): ?><tr><td style="color:#777">Phone</td><td><?= e($data['phone']) ?></td></tr><?php endif ?>
</table>
<p style="margin-top:18px;white-space:pre-wrap"><?= e($data['message']) ?></p>
