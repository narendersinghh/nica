<?php $site = config('site'); ?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Store unavailable · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/panel.css')) ?>">
</head>
<body>
<div class="standalone">
    <div class="card">
        <h1>Your store is <?= e($vendor['status'] === 'suspended' ? 'suspended' : 'disabled') ?></h1>
        <p>Your products are hidden from the shop and the dashboard is unavailable. Please contact us at <a href="mailto:<?= e($site['contact']['email']) ?>"><?= e($site['contact']['email']) ?></a> to sort this out.</p>
        <p><a class="btn btn--ghost" href="<?= e(link_to('/my-account/')) ?>">Go to my account</a></p>
    </div>
</div>
</body>
</html>
