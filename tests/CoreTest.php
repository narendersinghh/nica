<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Security\PasswordHasher;
use App\Core\Url;
use App\Core\Validator;

/* ---------- Router ---------- */

test('router matches static routes with or without trailing slash', function () {
    $r = new Router();
    $r->get('/about', fn () => 'about');
    [$route] = $r->resolve('GET', '/about/');
    assert_same('/about', $route->pattern);
    [$route] = $r->resolve('GET', '/about');
    assert_same('/about', $route->pattern);
});

test('router extracts params and multi-segment collection paths', function () {
    $r = new Router();
    $r->get('/product/{slug}', fn () => '');
    $r->get('/collection/{path:[a-z0-9/-]+}', fn () => '');
    [, $p] = $r->resolve('GET', '/product/yge-10/');
    assert_same(['slug' => 'yge-10'], $p);
    [, $p] = $r->resolve('GET', '/collection/hair-extensions/weft-bundles/deep-wave/');
    assert_same(['path' => 'hair-extensions/weft-bundles/deep-wave'], $p);
});

test('router builds named URLs with WordPress-style trailing slash', function () {
    $r = new Router();
    $r->get('/product/{slug}', fn () => '')->name('product');
    $r->get('/collection/{path:[a-z0-9/-]+}', fn () => '')->name('collection');
    $r->get('/sitemap_index.xml', fn () => '')->name('sitemap');
    assert_same('/product/yge-10/', $r->path('product', ['slug' => 'yge-10']));
    assert_same('/collection/hair-extensions/clip-ins/', $r->path('collection', ['path' => 'hair-extensions/clip-ins']));
    assert_same('/sitemap_index.xml', $r->path('sitemap'));
});

test('router regex special characters in literal parts are escaped', function () {
    $r = new Router();
    $r->get('/sitemap_index.xml', fn () => '');
    assert_throws(HttpException::class, fn () => $r->resolve('GET', '/sitemap_indexXxml'));
});

test('router returns 404 for unknown and 405 for wrong method', function () {
    $r = new Router();
    $r->post('/cart', fn () => '');
    $e = assert_throws(HttpException::class, fn () => $r->resolve('GET', '/nope'));
    assert_same(404, $e->status());
    $e = assert_throws(HttpException::class, fn () => $r->resolve('GET', '/cart'));
    assert_same(405, $e->status());
});

test('router groups apply prefix and middleware', function () {
    $r = new Router();
    $r->group(['prefix' => '/dashboard', 'middleware' => ['auth', 'role:vendor']], function (Router $r) {
        $r->get('/products', fn () => '')->name('vendor.products');
    });
    [$route] = $r->resolve('GET', '/dashboard/products/');
    assert_same(['auth', 'role:vendor'], $route->middleware);
});

/* ---------- Host / URL (no hard-coded domain) ---------- */

$makeUrl = function (array $server, array $trusted, array $proxies = [], string $force = '', bool $any = false): Url {
    return new Url(Request::create('GET', '/x', [], $server), $trusted, $proxies, $force, $any, sys_get_temp_dir());
};

test('url is built from the request host when trusted', function () use ($makeUrl) {
    $url = $makeUrl(['HTTP_HOST' => 'thenicacollection.com', 'HTTPS' => 'on'], ['thenicacollection.com']);
    assert_same('https://thenicacollection.com', $url->base());
    $url = $makeUrl(['HTTP_HOST' => 'staging.example.org'], ['*.example.org']);
    assert_same('http://staging.example.org/shop/', $url->to('/shop/'));
});

test('url rejects untrusted or malformed host headers', function () use ($makeUrl) {
    $e = assert_throws(HttpException::class, fn () => $makeUrl(['HTTP_HOST' => 'evil.com'], ['thenicacollection.com'])->base());
    assert_same(400, $e->status());
    assert_throws(HttpException::class, fn () => $makeUrl(['HTTP_HOST' => 'a.com/evil'], [], [], '', true)->base());
    assert_throws(HttpException::class, fn () => $makeUrl(['HTTP_HOST' => 'nicacollection.com.evil.com'], ['*.nicacollection.com'])->base());
});

test('url keeps a non-standard port', function () use ($makeUrl) {
    assert_same('http://localhost:8080', $makeUrl(['HTTP_HOST' => 'localhost:8080'], ['localhost'])->base());
});

test('forwarded proto is honoured only from trusted proxies', function () use ($makeUrl) {
    $server = ['HTTP_HOST' => 'example.com', 'HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '10.0.0.5'];
    assert_same('http', $makeUrl($server, ['example.com'])->scheme());
    assert_same('https', $makeUrl($server, ['example.com'], ['10.0.0.5'])->scheme());
    assert_same('https', $makeUrl($server, ['example.com'], ['*'])->scheme());
});

test('request detects sub-directory installs but not root rewrites into /public', function () {
    $sub = Request::create('GET', '/nica/public/shop/', [], ['SCRIPT_NAME' => '/nica/public/index.php']);
    assert_same('/nica/public', $sub->basePath());
    assert_same('/shop/', $sub->path());
    $root = Request::create('GET', '/shop/', [], ['SCRIPT_NAME' => '/public/index.php']);
    assert_same('', $root->basePath());
    assert_same('/shop/', $root->path());
    // PHP dev server: SCRIPT_NAME is the URL itself for file-like paths.
    $devServer = Request::create('GET', '/wp-content/uploads/2026/07/a.webp', [], ['SCRIPT_NAME' => '/wp-content/uploads/2026/07/a.webp']);
    assert_same('', $devServer->basePath());
    assert_same('/wp-content/uploads/2026/07/a.webp', $devServer->path());
});

/* ---------- Passwords (incl. imported WordPress hashes) ---------- */

test('argon2id hashes verify and do not need rehash', function () {
    $hash = PasswordHasher::hash('correct horse battery');
    assert_true(PasswordHasher::verify('correct horse battery', $hash));
    assert_false(PasswordHasher::verify('wrong', $hash));
    assert_false(PasswordHasher::needsRehash($hash));
});

test('legacy WordPress phpass hash verifies and is flagged for upgrade', function () {
    // Reference vector from the phpass project's own test suite.
    $hash = '$P$9IQRaTwmfeRo7ud9Fh4E2PdI0S3r.L0';
    assert_true(PasswordHasher::verify('test12345', $hash));
    assert_false(PasswordHasher::verify('test12346', $hash));
    assert_true(PasswordHasher::needsRehash($hash));
});

test('WordPress 6.8 "$wp$" bcrypt hash verifies', function () {
    $pre = base64_encode(hash_hmac('sha384', 'Secret#2026', 'wp-sha384', true));
    $hash = '$wp' . password_hash($pre, PASSWORD_BCRYPT);
    assert_true(PasswordHasher::verify('Secret#2026', $hash));
    assert_false(PasswordHasher::verify('secret#2026', $hash));
    assert_true(PasswordHasher::needsRehash($hash));
});

test('plain md5 legacy hash verifies; empty hash never does', function () {
    assert_true(PasswordHasher::verify('hello', md5('hello')));
    assert_false(PasswordHasher::verify('hello', null));
    assert_false(PasswordHasher::verify('', ''));
});

/* ---------- Validation ---------- */

test('validator enforces rules and returns only validated fields', function () {
    $v = new Validator(
        ['email' => ' Jane@Example.com ', 'age' => '29', 'role' => 'vendor', 'extra' => 'x', 'password' => 'abc', 'password_confirmation' => 'abd'],
        ['email' => 'required|email', 'age' => 'integer|min:18', 'role' => 'in:customer,vendor', 'password' => 'required|min:10|confirmed'],
    );
    assert_false($v->passes());
    assert_same(['password'], array_keys($v->errors()));
    assert_same('Jane@Example.com', $v->validated()['email']);
    assert_same(29, $v->validated()['age']);
    assert_false(isset($v->validated()['extra']));
});

test('validator rejects arrays where strings are expected', function () {
    $v = new Validator(['email' => ['a@b.com']], ['email' => 'required|email']);
    assert_false($v->passes());
});

/* ---------- Views / escaping ---------- */

test('e() escapes HTML and attribute quotes', function () {
    assert_same('&lt;script&gt;&quot;x&quot;&apos;', e('<script>"x"\''));
});

/* ---------- Env parsing ---------- */

test('env parser handles quotes, comments, booleans and references', function () {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "T_NAME=\"NiCa Co\"\nT_FLAG=true\nT_PLAIN=value # comment\nT_REF=\"\${T_NAME} Shop\"\nT_SINGLE='a#b \${T_NAME}'\nbad-key=1\n");
    Env::load($file);
    unlink($file);
    assert_same('NiCa Co', Env::get('T_NAME'));
    assert_same(true, Env::get('T_FLAG'));
    assert_same('value', Env::get('T_PLAIN'));
    assert_same('NiCa Co Shop', Env::get('T_REF'));
    assert_same('a#b ${T_NAME}', Env::get('T_SINGLE'));
});
