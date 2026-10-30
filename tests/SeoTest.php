<?php

declare(strict_types=1);

use App\Core\AssetMinifier;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Cache\PageCache;
use App\Services\Integrations;
use App\Services\Settings;
use App\Services\Sitemap;

test('sitemaps: Rank Math file names, only public URLs, current host', function () {
    $index = app()->handle(Request::create('GET', '/sitemap_index.xml'));
    assert_same(200, $index->status());
    assert_contains('application/xml', (string) $index->getHeader('Content-Type'));
    foreach (['post', 'page', 'product', 'category', 'product_cat'] as $type) {
        assert_contains('/' . $type . '-sitemap.xml</loc>', $index->body());
    }
    $pages = app()->handle(Request::create('GET', '/page-sitemap.xml'))->body();
    foreach (['/cart/', '/checkout/', '/my-account/', '/dashboard/', '/thank-you/'] as $private) {
        assert_false(str_contains($pages, $private . '</loc>'), $private . ' must not be listed');
    }
    assert_contains('<loc>' . app()->url()->to('/about/') . '</loc>', $pages);

    // Products from suspended / vacationing stores and drafts stay out.
    $db = app()->db();
    $hidden = $db->scalar("SELECT p.slug FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.status <> 'published' OR v.status <> 'approved' OR v.vacation_mode = 1 LIMIT 1");
    $products = app()->handle(Request::create('GET', '/product-sitemap.xml'))->body();
    if ($hidden) {
        assert_false(str_contains($products, '/product/' . $hidden . '/<'), 'hidden product listed');
    }
    assert_contains('<image:loc>', $products);
    assert_same(404, app()->handle(Request::create('GET', '/nonsense-sitemap.xml'))->status());
    assert_same(301, app()->handle(Request::create('GET', '/sitemap.xml'))->status());
    assert_same((int) $db->scalar("SELECT COUNT(*) FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.status = 'published' AND v.status = 'approved' AND v.vacation_mode = 0"), count((new Sitemap($db))->urls('product')));
});

test('robots.txt: local/staging block everything; sitemap URL uses the request host', function () {
    $res = app()->handle(Request::create('GET', '/robots.txt'));
    assert_same(200, $res->status());
    assert_contains('text/plain', (string) $res->getHeader('Content-Type'));
    assert_contains("Disallow: /\n", $res->body(), 'non-production must not be indexed');
    assert_same('noindex, nofollow', app()->handle(Request::create('GET', '/about/'))->getHeader('X-Robots-Tag'));
});

test('product feed: off by default, one item per variation when on', function () {
    $db = app()->db();
    $saved = $db->scalar("SELECT value FROM settings WHERE name = 'integrations'");
    try {
        $db->query("DELETE FROM settings WHERE name = 'integrations'");
        Settings::flush();
        assert_same(404, app()->handle(Request::create('GET', '/product-feed.xml'))->status());
        $db->insert('settings', ['name' => 'integrations', 'value' => json_encode(['google_merchant' => ['enabled' => true]])]);
        Settings::flush();
        $xml = app()->handle(Request::create('GET', '/product-feed.xml'))->body();
        $doc = simplexml_load_string($xml);
        assert_true($doc !== false, 'valid XML');
        $items = $doc->channel->item;
        assert_true(count($items) > 0);
        $g = $items[0]->children('http://base.google.com/ns/1.0');
        assert_true((bool) preg_match('/^\d+\.\d{2} USD$/', (string) $g->price));
        assert_true(in_array((string) $g->availability, ['in_stock', 'out_of_stock', 'backorder'], true));
    } finally {
        $db->query("DELETE FROM settings WHERE name = 'integrations'");
        if ($saved !== null && $saved !== false) {
            $db->insert('settings', ['name' => 'integrations', 'value' => $saved]);
        }
        Settings::flush();
    }
});

test('page cache: anonymous pages cached without tokens; nonce swapped; flushed by admin changes', function () {
    PageCache::flush();
    // CLI tests always have a "session", so call the cache directly the way the middleware would.
    $key = PageCache::key('localhost', '/about/');
    app()->renderingForCache(true);
    assert_same('', csrf_token(), 'no token while rendering for the cache');
    app()->renderingForCache(false);
    PageCache::put($key, '<script nonce="OLDNONCE"></script>', 'OLDNONCE', ['Content-Type' => 'text/html; charset=UTF-8']);
    assert_same('OLDNONCE', PageCache::get($key)['nonce']);
    PageCache::flush();
    assert_same(null, PageCache::get(PageCache::key('localhost', '/about/')), 'flush invalidates');

    // A POST in the admin area flushes; a customer POST does not.
    $before = PageCache::version();
    app()->handle(Request::create('POST', '/cart/update', ['_token' => app()->csrf()->token()]));
    assert_same($before, PageCache::version());
    $admin = app()->db()->first("SELECT u.* FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' LIMIT 1");
    app()->auth()->login($admin, '127.0.0.1', false);
    try {
        app()->handle(Request::create('POST', '/admin/redirects/dismiss', ['_token' => app()->csrf()->token(), 'path' => '/does-not-exist/']));
        assert_true($before !== PageCache::version(), 'admin change bumps the cache version');
        app()->db()->delete('audit_log', ['user_id' => (int) $admin['id']]);
    } finally {
        app()->auth()->logout();
    }
});

test('csrf-token endpoint, gzip and asset minifier', function () {
    $res = app()->handle(Request::create('GET', '/csrf-token'));
    assert_same(200, $res->status());
    assert_same(64, strlen((string) (json_decode($res->body(), true)['token'] ?? '')));
    assert_contains('no-store', (string) $res->getHeader('Cache-Control'));

    $big = Response::html(str_repeat('<p>hello world</p>', 200));
    $gz = $big->compress('gzip, deflate, br');
    assert_same('gzip', $gz->getHeader('Content-Encoding'));
    assert_same(str_repeat('<p>hello world</p>', 200), gzdecode($gz->body()));
    assert_same(null, Response::html('<p>tiny</p>')->compress('gzip')->getHeader('Content-Encoding'), 'small bodies left alone');

    // A space before ":" is kept on purpose: "a :hover" and "a:hover" mean different things.
    $css = "/* c */\na { color : red ; }\n@media (min-width: 10px) and (max-width: 20px) {\n  .b > .c { background: url(\"x y.png\"); content: \"a  b\"; }\n}\n";
    assert_same('a{color :red}@media (min-width:10px) and (max-width:20px){.b>.c{background:url("x y.png");content:"a  b"}}' . "\n", AssetMinifier::css($css));
    $js = "// comment\nconst a = 'http://x'; // keep\n/* block\n comment */\n  let b = 1;\n";
    assert_same("const a = 'http://x'; // keep\nlet b = 1;\n", AssetMinifier::js($js));
});

test('integrations: nothing loads or widens the CSP until an ID is set', function () {
    $db = app()->db();
    $saved = $db->scalar("SELECT value FROM settings WHERE name = 'integrations'");
    try {
        $db->query("DELETE FROM settings WHERE name = 'integrations'");
        Settings::flush();
        assert_false(Integrations::anyTracking());
        assert_same([], Integrations::cspSources());
        $db->insert('settings', ['name' => 'integrations', 'value' => json_encode(['ga4' => ['measurement_id' => 'G-ABC1234'], 'meta_pixel' => ['pixel_id' => 'not-a-number']])]);
        Settings::flush();
        assert_same(['ga4' => 'G-ABC1234'], Integrations::clientConfig(), 'invalid IDs are ignored');
        assert_true(in_array('https://www.googletagmanager.com', Integrations::cspSources()['script-src'], true));
        assert_false(isset(Integrations::cspSources()['frame-src']), 'no Meta sources without a valid pixel');
    } finally {
        $db->query("DELETE FROM settings WHERE name = 'integrations'");
        if ($saved !== null && $saved !== false) {
            $db->insert('settings', ['name' => 'integrations', 'value' => $saved]);
        }
        Settings::flush();
    }
});
