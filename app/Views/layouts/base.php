<?php /** @var App\Core\Template $this */ ?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? config('app.name')) ?></title>
    <?php if (!empty($robots)): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif ?>
    <meta name="csrf-token" content="<?= e(app()->session()->isStarted() ? csrf_token() : '') ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <?= $this->section('head') ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $this->section('content') ?>
<?= $this->section('scripts') ?>
</body>
</html>
