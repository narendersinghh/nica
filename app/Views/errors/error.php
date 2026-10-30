<?php
/** Error page in the site layout; falls back to a bare page if the layout itself fails. @var App\Core\Template $this */
$this->layout('layouts/site', [
    'seo' => (function () use ($status) {
        $seo = App\Services\Seo::make('', ($status === 404 ? 'Page Not Found' : 'Error') . ' - ' . config('app.name'));
        $seo->robots = 'noindex';
        $seo->canonical = null;
        return $seo;
    })(),
    'bodyClass' => 'page-error',
]);
?>
<section class="error-page">
    <div class="container error-page__inner">
        <p class="error-page__code" aria-hidden="true"><?= (int) $status ?></p>
        <h1><?= $status === 404 ? 'Page not found' : e($message) ?></h1>
        <p><?= $status === 404 ? 'This page wasn\'t found or doesn\'t exist. Please visit the main page and start navigating.' : 'Please try again in a moment.' ?></p>
        <a class="btn btn--gold" href="<?= e(link_to('/')) ?>"><?= icon('chevron-left') ?>Back to Main Page</a>
        <?php if ($exception !== null): ?>
            <pre class="debug"><?= e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString()) ?></pre>
        <?php endif ?>
    </div>
</section>
