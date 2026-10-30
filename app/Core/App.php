<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\Kernel;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Security\Auth;
use App\Core\Security\Csrf;
use App\Core\Security\RateLimiter;
use App\Core\Security\Session;

/**
 * Application container. Services are created lazily on first use so a cached
 * page or a static 404 never opens a database connection.
 */
final class App
{
    private static ?App $instance = null;

    private Config $config;
    private ?Request $request = null;
    private ?Router $router = null;
    private ?Url $url = null;
    private ?Database $db = null;
    private ?View $view = null;
    private ?Session $session = null;
    private ?Csrf $csrf = null;
    private ?Auth $auth = null;
    private ?RateLimiter $limiter = null;
    private ?Logger $logger = null;
    private ?\App\Services\Media\Images $images = null;
    private ?\App\Services\Catalog\Catalog $catalog = null;
    private ?\App\Services\Cart\Cart $cart = null;
    private ?string $nonce = null;

    public function __construct(private string $basePath)
    {
        $this->config = new Config($basePath . '/config');
        self::$instance = $this;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('Application has not been bootstrapped.');
        }
        return self::$instance;
    }

    public function handle(Request $request): Response
    {
        $this->setRequest($request);
        return (new Kernel($this))->handle($request);
    }

    public function path(string $relative = ''): string
    {
        return $this->basePath . ($relative === '' ? '' : '/' . ltrim($relative, '/'));
    }

    public function isLocal(): bool
    {
        return $this->config->get('app.env') === 'local';
    }

    public function isProduction(): bool
    {
        return $this->config->get('app.env') === 'production';
    }

    public function debug(): bool
    {
        return (bool) $this->config->get('app.debug');
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->url = null;
        $this->nonce = null;
    }

    public function request(): Request
    {
        return $this->request ??= Request::capture();
    }

    public function router(): Router
    {
        if ($this->router === null) {
            $this->router = new Router();
            $router = $this->router;
            require $this->path('routes/web.php');
        }
        return $this->router;
    }

    public function url(): Url
    {
        return $this->url ??= new Url(
            $this->request(),
            (array) $this->config->get('app.trusted_hosts', []),
            (array) $this->config->get('app.trusted_proxies', []),
            (string) $this->config->get('app.force_scheme', ''),
            $this->isLocal(),
            $this->path('public'),
        );
    }

    public function db(): Database
    {
        return $this->db ??= new Database((array) $this->config->get('database'));
    }

    public function view(): View
    {
        return $this->view ??= new View($this->path('app/Views'));
    }

    public function session(): Session
    {
        return $this->session ??= new Session(
            $this->path('storage/sessions'),
            (array) $this->config->get('security.session'),
        );
    }

    public function csrf(): Csrf
    {
        return $this->csrf ??= new Csrf($this->session());
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this->db(), $this->session());
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->db());
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->path('storage/logs'));
    }

    /** The visitor's cart (created lazily; reading never creates one). */
    public function cart(): \App\Services\Cart\Cart
    {
        return $this->cart ??= new \App\Services\Cart\Cart($this->db(), $this->request(), $this->auth()->id());
    }

    public function catalog(): \App\Services\Catalog\Catalog
    {
        return $this->catalog ??= new \App\Services\Catalog\Catalog($this->db());
    }

    public function images(): \App\Services\Media\Images
    {
        return $this->images ??= new \App\Services\Media\Images($this->db());
    }

    /** Per-request nonce for inline <script>/<style> allowed by the CSP. */
    private bool $cacheRender = false;

    /** True while rendering a page for the shared page cache (no per-visitor data allowed). */
    public function renderingForCache(?bool $on = null): bool
    {
        if ($on !== null) {
            $this->cacheRender = $on;
        }
        return $this->cacheRender;
    }

    public function cspNonce(): string
    {
        return $this->nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
