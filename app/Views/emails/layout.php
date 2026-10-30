<?php /* Email HTML: inline styles only (email clients ignore stylesheets). */ ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title><?= e($heading ?? config('app.name')) ?></title></head>
<body style="margin:0;padding:0;background:#FAF4EE;font-family:Arial,Helvetica,sans-serif;color:#4D4D4D">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF4EE;padding:24px 12px">
    <tr><td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">
            <tr><td style="background:#2C211C;padding:22px 28px;color:#DFB467;font-family:Georgia,serif;font-size:22px"><?= e(config('app.name')) ?></td></tr>
            <tr><td style="padding:28px">
                <?php if (!empty($heading)): ?><h1 style="margin:0 0 16px;font-family:Georgia,serif;font-weight:normal;font-size:26px;color:#000"><?= e($heading) ?></h1><?php endif ?>
                <?= $this->section('content') ?>
            </td></tr>
            <tr><td style="padding:18px 28px;background:#F6ECE3;font-size:12px;color:#777">© <?= date('Y') ?> The NiCa Collection LLC</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
