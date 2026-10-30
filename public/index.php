<?php

declare(strict_types=1);

// Built-in dev server only: serve real static files with proper types and cache
// headers. Never dotfiles or PHP, never anything outside public/.
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $file = realpath(__DIR__ . $path);
    if ($path !== '/' && $file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)
        && !preg_match('#/\.|\.(php\d?|phtml|phar)$#i', $path)) {
        $types = [
            'css' => 'text/css; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'json' => 'application/json',
            'woff2' => 'font/woff2', 'woff' => 'font/woff', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
            'avif' => 'image/avif', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'ico' => 'image/x-icon', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=UTF-8',
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        $gzip = in_array($ext, ['css', 'js', 'svg', 'json', 'txt'], true) && str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip');
        $body = $gzip ? gzencode((string) file_get_contents($file), 6) : null;
        if ($gzip) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
        }
        header('Content-Length: ' . ($gzip ? strlen($body) : filesize($file)));
        header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600'));
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            $gzip ? print($body) : readfile($file);
        }
        return true;
    }
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$app->handle(App\Core\Http\Request::capture())->compress((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''))->send();
