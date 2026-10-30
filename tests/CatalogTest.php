<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Services\Catalog\ProductIndexer;

$hasCatalog = static function (): bool {
    try {
        return (int) app()->db()->scalar('SELECT COUNT(*) FROM products') > 0;
    } catch (\Throwable) {
        return false;
    }
};

/** Run a GET request through the full kernel and decode JSON. */
$getJson = static function (string $uri): array {
    $response = app()->handle(Request::create('GET', $uri, [], ['HTTP_ACCEPT' => 'application/json']));
    return [$response->status(), json_decode($response->body(), true)];
};

test('sale price applies only inside its schedule', function () {
    $v = ['regular_price_cents' => 10000, 'sale_price_cents' => 8000, 'sale_starts_at' => null, 'sale_ends_at' => null, 'is_active' => 1, 'stock_status' => 'instock', 'manage_stock' => 0, 'stock_qty' => null];
    assert_same(8000, ProductIndexer::effectivePrice($v, '2026-10-30 12:00:00'));
    assert_same(10000, ProductIndexer::effectivePrice(['sale_starts_at' => '2026-11-01 00:00:00'] + $v, '2026-10-30 12:00:00'));
    assert_same(10000, ProductIndexer::effectivePrice(['sale_ends_at' => '2026-10-01 00:00:00'] + $v, '2026-10-30 12:00:00'));
    assert_same(10000, ProductIndexer::effectivePrice(['sale_price_cents' => 12000] + $v), 'a "sale" above regular is ignored');
});

test('stock rules: managed stock at zero is not purchasable, backorders are', function () {
    $v = ['is_active' => 1, 'stock_status' => 'instock', 'manage_stock' => 1, 'stock_qty' => 0];
    assert_false(ProductIndexer::isPurchasable($v));
    assert_true(ProductIndexer::isPurchasable(['stock_status' => 'onbackorder'] + $v));
    assert_true(ProductIndexer::isPurchasable(['manage_stock' => 0] + $v));
    assert_false(ProductIndexer::isPurchasable(['is_active' => 0, 'manage_stock' => 0] + $v));
});

test('catalog: listing counts, category tree and price sort', function () use ($hasCatalog) {
    if (!$hasCatalog()) {
        return;
    }
    $catalog = app()->catalog();
    $all = $catalog->products(['sort' => 'latest']);
    assert_same(80, $all['total'], 'published products of approved vendors');
    assert_same(7, $all['pages']);
    $he = $catalog->categoryByPath('hair-extensions');
    $inHe = $catalog->products(['category' => (int) $he['id']]);
    $weft = $catalog->products(['category' => (int) $catalog->categoryByPath('hair-extensions/weft-bundles')['id']]);
    assert_true($inHe['total'] >= $weft['total'] && $weft['total'] > 0, 'parent includes child categories');
    $cheap = $catalog->products(['sort' => 'price'], 1, 50)['items'];
    $prices = array_map('intval', array_column($cheap, 'price_min_cents'));
    $sorted = $prices;
    sort($sorted);
    assert_same($sorted, $prices);
});

test('catalog: unapproved vendors and unpublished products stay hidden', function () use ($hasCatalog) {
    if (!$hasCatalog()) {
        return;
    }
    $hidden = (int) app()->db()->scalar("SELECT COUNT(*) FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.status <> 'published' OR v.status <> 'approved'");
    $slug = app()->db()->scalar("SELECT slug FROM products WHERE status <> 'published' LIMIT 1");
    assert_true($hidden > 0);
    assert_same(null, app()->catalog()->productBySlug((string) $slug));
    assert_same(null, app()->catalog()->vendorBySlug('silky-tresses'));
});

test('match API: strict matches first, soft fallback when nothing matches all', function () use ($hasCatalog, $getJson) {
    if (!$hasCatalog()) {
        return;
    }
    // Hair Extensions + Straight + Long
    [$status, $strict] = $getJson('/api/match?cat=3&tex=2&length=3');
    assert_same(200, $status);
    assert_false($strict['soft']);
    assert_true(count($strict['items']) > 0 && count($strict['items']) <= 12);
    assert_same(99, $strict['items'][0]['match']);
    // Closures + Red: impossible together, so the closest closures come back as "soft".
    [, $soft] = $getJson('/api/match?cat=1&color=4');
    assert_true($soft['soft'] || $soft['items'] === []);
    // Garbage input is ignored, not an error.
    [$status] = $getJson('/api/match?cat=../../etc&tex=1;DROP');
    assert_same(200, $status);
});

test('review submission is stored as pending and not shown', function () use ($hasCatalog) {
    if (!$hasCatalog()) {
        return;
    }
    $db = app()->db();
    $product = $db->first("SELECT id, slug FROM products WHERE status = 'published' LIMIT 1");
    $before = count(app()->catalog()->reviews((int) $product['id']));
    $token = app()->csrf()->token();
    $res = app()->handle(Request::create('POST', '/product/' . $product['slug'] . '/review', [
        '_token' => $token, 'rating' => '5', 'body' => 'Lovely hair, soft and full.', 'author_name' => 'Test', 'author_email' => 'reviewer@nica.test',
    ]));
    try {
        assert_same(303, $res->status());
        assert_same(1, (int) $db->scalar("SELECT COUNT(*) FROM reviews WHERE author_email = 'reviewer@nica.test' AND status = 'pending'"));
        assert_same($before, count(app()->catalog()->reviews((int) $product['id'])), 'pending reviews are not public');
    } finally {
        $db->query("DELETE FROM reviews WHERE author_email = 'reviewer@nica.test'");
        $db->query("DELETE FROM rate_limits WHERE k LIKE 'throttle:contact:%'");
    }
});
