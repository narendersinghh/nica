<?php

/** @var App\Core\Http\Router $router */

use App\Http\Controllers\Admin\AuditController as AdminAudit;
use App\Http\Controllers\Admin\CouponsController as AdminCoupons;
use App\Http\Controllers\Admin\CustomersController as AdminCustomers;
use App\Http\Controllers\Admin\DashboardController as AdminDashboard;
use App\Http\Controllers\Admin\OrdersController as AdminOrders;
use App\Http\Controllers\Admin\PayoutsController as AdminPayouts;
use App\Http\Controllers\Admin\ProductsController as AdminProducts;
use App\Http\Controllers\Admin\RedirectsController as AdminRedirects;
use App\Http\Controllers\Admin\ReviewsController as AdminReviews;
use App\Http\Controllers\Admin\SettingsController as AdminSettings;
use App\Http\Controllers\Admin\StylistsController as AdminStylists;
use App\Http\Controllers\Admin\UpdatesController as AdminUpdates;
use App\Http\Controllers\Admin\VendorsController as AdminVendors;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\LegacyController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StylistController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\Vendor\CouponController as VendorCoupons;
use App\Http\Controllers\Vendor\DashboardController as VendorDashboard;
use App\Http\Controllers\Vendor\EarningsController as VendorEarnings;
use App\Http\Controllers\Vendor\OrderController as VendorOrders;
use App\Http\Controllers\Vendor\ProductController as VendorProducts;
use App\Http\Controllers\Vendor\ReviewController as VendorReviews;
use App\Http\Controllers\Vendor\SettingsController as VendorSettings;

// Public site. URLs mirror the current WordPress site exactly (trailing slashes included).
$router->get('/', [PageController::class, 'home'])->name('home')->middleware('cache');

// Catalog
$router->get('/shop', [ShopController::class, 'shop'])->name('shop')->middleware('cache');
$router->get('/shop/page/{page:\d+}', [ShopController::class, 'shop'])->middleware('cache');
$router->get('/collection/{path:[a-z0-9-]+(?:/[a-z0-9-]+)*?}/page/{page:\d+}', [ShopController::class, 'collection'])->middleware('cache');
$router->get('/collection/{path:[a-z0-9-]+(?:/[a-z0-9-]+)*}', [ShopController::class, 'collection'])->name('collection')->middleware('cache');
$router->get('/hair-texture/{slug:[a-z0-9-]+}', [ShopController::class, 'texture'])->name('texture')->middleware('cache');
$router->get('/hair-texture/{slug:[a-z0-9-]+}/page/{page:\d+}', [ShopController::class, 'texture'])->middleware('cache');
$router->get('/product/{slug:[a-z0-9-]+}', [ProductController::class, 'show'])->name('product')->middleware('cache');
$router->post('/product/{slug:[a-z0-9-]+}/review', [ProductController::class, 'review'])->middleware('throttle:contact')->name('product.review');
$router->get('/cart', [CartController::class, 'show'])->name('cart');
$router->get('/cart/summary', [CartController::class, 'summary'])->withoutTrailingSlash();
$router->post('/cart/add', [CartController::class, 'add'])->middleware('throttle:cart')->name('cart.add');
$router->post('/cart/update', [CartController::class, 'update'])->middleware('throttle:cart');
$router->post('/cart/remove', [CartController::class, 'remove'])->middleware('throttle:cart');
$router->post('/cart/coupon', [CartController::class, 'coupon'])->middleware('throttle:coupon');
$router->post('/cart/coupon/remove', [CartController::class, 'removeCoupon'])->middleware('throttle:cart');
$router->post('/cart/shipping', [CartController::class, 'shipping'])->middleware('throttle:cart');
$router->post('/cart/points', [CartController::class, 'points'])->middleware('throttle:cart');
$router->get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
$router->post('/checkout/place', [CheckoutController::class, 'place'])->withoutTrailingSlash()->middleware('throttle:checkout');
$router->get('/checkout/complete', [CheckoutController::class, 'complete']);
$router->post('/checkout/test-pay', [CheckoutController::class, 'testPay'])->withoutTrailingSlash();
$router->post('/webhooks/stripe', [CheckoutController::class, 'webhook'])->withoutTrailingSlash();
$router->get('/store-listing', [StoreController::class, 'index'])->name('stores')->middleware('cache');
$router->get('/store/{slug:[a-z0-9-]+}', [StoreController::class, 'show'])->name('store')->middleware('cache');
$router->get('/store/{slug:[a-z0-9-]+}/page/{page:\d+}', [StoreController::class, 'show']);
$router->get('/store/{slug:[a-z0-9-]+}/reviews', [StoreController::class, 'reviews'])->name('store.reviews');

// Customer account (/my-account/ shows login/register to guests)
$router->get('/my-account', [AccountController::class, 'dashboard'])->name('account');
$router->post('/my-account/login', [AuthController::class, 'login'])->name('login');
$router->post('/my-account/register', [AuthController::class, 'register'])->name('register');
$router->post('/my-account/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
$router->get('/my-account/two-factor', [AuthController::class, 'showTwoFactor']);
$router->post('/my-account/two-factor', [AuthController::class, 'verifyTwoFactor']);
$router->get('/my-account/lost-password', [AuthController::class, 'showLostPassword'])->name('password.lost');
$router->post('/my-account/lost-password', [AuthController::class, 'sendResetLink']);
$router->get('/my-account/reset-password', [AuthController::class, 'showResetPassword'])->name('password.reset');
$router->post('/my-account/reset-password', [AuthController::class, 'resetPassword']);
$router->group(['prefix' => '/my-account', 'middleware' => ['auth']], function ($router) {
    $router->get('/orders', [AccountController::class, 'orders'])->name('account.orders');
    $router->get('/orders/page/{page:\d+}', [AccountController::class, 'orders']);
    $router->get('/view-order/{number:[0-9A-Za-z-]+}', [AccountController::class, 'viewOrder'])->name('account.order');
    $router->get('/edit-address', [AccountController::class, 'addresses'])->name('account.addresses');
    $router->get('/edit-address/{type:billing|shipping}', [AccountController::class, 'editAddress']);
    $router->post('/edit-address/{type:billing|shipping}', [AccountController::class, 'saveAddress']);
    $router->get('/edit-account', [AccountController::class, 'editAccount'])->name('account.details');
    $router->post('/edit-account', [AccountController::class, 'saveAccount']);
    $router->get('/points', [AccountController::class, 'points'])->name('account.points');
    $router->get('/security', [AccountController::class, 'security'])->name('account.security');
    $router->post('/security/enable', [AccountController::class, 'enableTwoFactor']);
    $router->post('/security/disable', [AccountController::class, 'disableTwoFactor']);
    $router->get('/customer-logout', [AccountController::class, 'confirmLogout']);
    $router->get('/stylist', [StylistController::class, 'show'])->name('account.stylist');
    $router->post('/stylist/join', [StylistController::class, 'join'])->middleware('throttle:contact');
    $router->post('/stylist/payout', [StylistController::class, 'savePayout']);
    $router->post('/stylist/withdraw', [StylistController::class, 'withdraw'])->middleware('throttle:payout');
    $router->post('/stylist/withdraw/{id:\d+}/cancel', [StylistController::class, 'cancel']);
});
$router->get('/my-orders', [AccountController::class, 'orders'])->middleware('auth');

// Vendor dashboard (Dokan's /dashboard/ URLs where they existed)
$router->group(['prefix' => '/dashboard', 'middleware' => ['vendor']], function ($router) {
    $router->get('', [VendorDashboard::class, 'overview'])->name('vendor');
    $router->get('/products', [VendorProducts::class, 'index'])->name('vendor.products');
    $router->get('/products/page/{page:\d+}', [VendorProducts::class, 'index']);
    $router->get('/products/new', [VendorProducts::class, 'create']);
    $router->post('/products/new', [VendorProducts::class, 'store']);
    $router->get('/new-product', [VendorProducts::class, 'create']);
    $router->post('/products/stock', [VendorProducts::class, 'stock'])->withoutTrailingSlash();
    $router->get('/products/{id:\d+}', [VendorProducts::class, 'edit']);
    $router->post('/products/{id:\d+}', [VendorProducts::class, 'update']);
    $router->post('/products/{id:\d+}/duplicate', [VendorProducts::class, 'duplicate']);
    $router->post('/products/{id:\d+}/archive', [VendorProducts::class, 'archive']);
    $router->post('/products/{id:\d+}/restore', [VendorProducts::class, 'restore']);
    $router->get('/orders', [VendorOrders::class, 'index'])->name('vendor.orders');
    $router->get('/orders/page/{page:\d+}', [VendorOrders::class, 'index']);
    $router->get('/orders/{id:\d+}', [VendorOrders::class, 'show']);
    $router->get('/orders/{id:\d+}/slip', [VendorOrders::class, 'slip']);
    $router->post('/orders/{id:\d+}/ship', [VendorOrders::class, 'ship']);
    $router->post('/orders/{id:\d+}/complete', [VendorOrders::class, 'complete']);
    $router->post('/orders/{id:\d+}/note', [VendorOrders::class, 'note']);
    $router->get('/withdraw', [VendorEarnings::class, 'index'])->name('vendor.withdraw');
    $router->get('/withdraw/page/{page:\d+}', [VendorEarnings::class, 'index']);
    $router->post('/withdraw/request', [VendorEarnings::class, 'request'])->middleware('throttle:payout');
    $router->post('/withdraw/{id:\d+}/cancel', [VendorEarnings::class, 'cancel']);
    $router->get('/coupons', [VendorCoupons::class, 'index']);
    $router->get('/coupons/new', [VendorCoupons::class, 'create']);
    $router->post('/coupons/new', [VendorCoupons::class, 'store']);
    $router->get('/coupons/{id:\d+}', [VendorCoupons::class, 'edit']);
    $router->post('/coupons/{id:\d+}', [VendorCoupons::class, 'update']);
    $router->post('/coupons/{id:\d+}/delete', [VendorCoupons::class, 'delete']);
    $router->get('/reviews', [VendorReviews::class, 'index']);
    $router->get('/reviews/page/{page:\d+}', [VendorReviews::class, 'index']);
    $router->post('/reviews/{id:\d+}/reply', [VendorReviews::class, 'reply']);
    $router->get('/settings', [VendorSettings::class, 'store']);
    $router->get('/settings/store', [VendorSettings::class, 'store']);
    $router->post('/settings/store', [VendorSettings::class, 'saveStore']);
    $router->get('/settings/payment', [VendorSettings::class, 'payment']);
    $router->post('/settings/payment', [VendorSettings::class, 'savePayment']);
    $router->post('/settings/payment/stripe', [VendorSettings::class, 'connectStripe'])->middleware('throttle:payout');
    $router->post('/settings/payment/stripe/dashboard', [VendorSettings::class, 'stripeDashboard']);
    $router->post('/settings/payment/stripe/disconnect', [VendorSettings::class, 'disconnectStripe']);
});

// Admin panel. 'admin' = admin or staff; 'admin:<ability>' narrows it (see AdminAccess::STAFF).
$router->post('/admin/impersonate/stop', [AdminVendors::class, 'stopImpersonating'])->withoutTrailingSlash()->middleware('auth');
$router->group(['prefix' => '/admin', 'middleware' => ['admin']], function ($router) {
    $router->get('', [AdminDashboard::class, 'index'])->name('admin');
    $router->get('/orders', [AdminOrders::class, 'index'])->middleware('admin:orders');
    $router->get('/orders/page/{page:\d+}', [AdminOrders::class, 'index'])->middleware('admin:orders');
    $router->get('/orders/export', [AdminOrders::class, 'export'])->withoutTrailingSlash()->middleware('admin:finance');
    $router->get('/orders/{id:\d+}', [AdminOrders::class, 'show'])->middleware('admin:orders');
    $router->post('/orders/{id:\d+}/refund', [AdminOrders::class, 'refund'])->middleware('admin:orders');
    $router->post('/orders/{id:\d+}/note', [AdminOrders::class, 'note'])->middleware('admin:orders');
    $router->post('/orders/{id:\d+}/resend', [AdminOrders::class, 'resend'])->middleware('admin:orders');
    $router->post('/orders/{id:\d+}/{action:cancel|release|complete}', [AdminOrders::class, 'action'])->middleware('admin:orders');
    $router->get('/products', [AdminProducts::class, 'index'])->middleware('admin:products');
    $router->get('/products/page/{page:\d+}', [AdminProducts::class, 'index'])->middleware('admin:products');
    $router->get('/products/{id:\d+}', [AdminProducts::class, 'edit'])->middleware('admin:products');
    $router->post('/products/{id:\d+}', [AdminProducts::class, 'update'])->middleware('admin:products');
    $router->post('/products/{id:\d+}/{action:approve|reject|feature|unfeature|verify|unverify|archive}', [AdminProducts::class, 'action'])->middleware('admin:products');
    $router->get('/vendors', [AdminVendors::class, 'index'])->middleware('admin:vendors.view');
    $router->get('/vendors/{id:\d+}', [AdminVendors::class, 'show'])->middleware('admin:vendors.view');
    $router->post('/vendors/{id:\d+}', [AdminVendors::class, 'update'])->middleware('admin:vendors');
    $router->post('/vendors/{id:\d+}/{action:approve|suspend|reject|reactivate}', [AdminVendors::class, 'status'])->middleware('admin:vendors');
    $router->post('/vendors/{id:\d+}/impersonate', [AdminVendors::class, 'impersonate'])->middleware('admin:vendors');
    $router->get('/payouts', [AdminPayouts::class, 'index'])->middleware('admin:finance');
    $router->get('/payouts/export', [AdminPayouts::class, 'export'])->withoutTrailingSlash()->middleware('admin:finance');
    $router->get('/payouts/{id:\d+}', [AdminPayouts::class, 'show'])->middleware('admin:finance');
    $router->post('/payouts/{id:\d+}/pay', [AdminPayouts::class, 'pay'])->middleware('admin:finance');
    $router->post('/payouts/{id:\d+}/reject', [AdminPayouts::class, 'reject'])->middleware('admin:finance');
    $router->get('/payouts/ledger/{id:\d+}', [AdminPayouts::class, 'ledger'])->middleware('admin:finance');
    $router->get('/payouts/ledger/{id:\d+}/page/{page:\d+}', [AdminPayouts::class, 'ledger'])->middleware('admin:finance');
    $router->post('/payouts/ledger/{id:\d+}/adjust', [AdminPayouts::class, 'adjust'])->middleware('admin:finance');
    $router->get('/payouts/stylist/{id:\d+}', [AdminPayouts::class, 'show'])->middleware('admin:finance');
    $router->post('/payouts/stylist/{id:\d+}/pay', [AdminPayouts::class, 'pay'])->middleware('admin:finance');
    $router->post('/payouts/stylist/{id:\d+}/reject', [AdminPayouts::class, 'reject'])->middleware('admin:finance');
    $router->get('/stylists', [AdminStylists::class, 'index'])->middleware('admin:stylists');
    $router->get('/stylists/{id:\d+}', [AdminStylists::class, 'show'])->middleware('admin:stylists');
    $router->get('/stylists/{id:\d+}/page/{page:\d+}', [AdminStylists::class, 'show'])->middleware('admin:stylists');
    $router->post('/stylists/{id:\d+}', [AdminStylists::class, 'update'])->middleware('admin:stylists');
    $router->post('/stylists/{id:\d+}/{action:approve|suspend|reject|reactivate}', [AdminStylists::class, 'status'])->middleware('admin:stylists');
    $router->post('/stylists/{id:\d+}/adjust', [AdminStylists::class, 'adjust'])->middleware('admin:finance');
    $router->get('/customers', [AdminCustomers::class, 'index'])->middleware('admin:customers');
    $router->get('/customers/page/{page:\d+}', [AdminCustomers::class, 'index'])->middleware('admin:customers');
    $router->get('/customers/{id:\d+}', [AdminCustomers::class, 'show'])->middleware('admin:customers');
    $router->post('/customers/{id:\d+}/roles', [AdminCustomers::class, 'roles'])->middleware('admin:settings');
    $router->post('/customers/{id:\d+}/{action:suspend|activate}', [AdminCustomers::class, 'status'])->middleware('admin:customers');
    $router->post('/customers/{id:\d+}/reset-password', [AdminCustomers::class, 'sendReset'])->middleware('admin:customers');
    $router->post('/customers/{id:\d+}/points', [AdminCustomers::class, 'points'])->middleware('admin:finance');
    $router->get('/reviews', [AdminReviews::class, 'index'])->middleware('admin:reviews');
    $router->get('/reviews/page/{page:\d+}', [AdminReviews::class, 'index'])->middleware('admin:reviews');
    $router->post('/reviews/{id:\d+}/{action:approve|spam|reject|feature|unfeature|delete}', [AdminReviews::class, 'action'])->middleware('admin:reviews');
    $router->get('/coupons', [AdminCoupons::class, 'index'])->middleware('admin:marketing');
    $router->get('/coupons/new', [AdminCoupons::class, 'create'])->middleware('admin:marketing');
    $router->post('/coupons/new', [AdminCoupons::class, 'save'])->middleware('admin:marketing');
    $router->get('/coupons/{id:\d+}', [AdminCoupons::class, 'edit'])->middleware('admin:marketing');
    $router->post('/coupons/{id:\d+}', [AdminCoupons::class, 'save'])->middleware('admin:marketing');
    $router->post('/coupons/{id:\d+}/delete', [AdminCoupons::class, 'delete'])->middleware('admin:marketing');
    $router->get('/redirects', [AdminRedirects::class, 'index'])->middleware('admin:seo');
    $router->post('/redirects', [AdminRedirects::class, 'store'])->middleware('admin:seo');
    $router->post('/redirects/{id:\d+}/delete', [AdminRedirects::class, 'delete'])->middleware('admin:seo');
    $router->post('/redirects/dismiss', [AdminRedirects::class, 'dismiss'])->withoutTrailingSlash()->middleware('admin:seo');
    $router->get('/settings', [AdminSettings::class, 'index'])->middleware('admin:settings');
    $router->post('/settings', [AdminSettings::class, 'save'])->middleware('admin:settings');
    $router->get('/updates', [AdminUpdates::class, 'index'])->middleware('admin:updates');
    $router->post('/updates/install', [AdminUpdates::class, 'install'])->middleware('admin:updates', 'throttle:updates');
    $router->post('/updates/finish', [AdminUpdates::class, 'finish'])->middleware('admin:updates');
    $router->post('/updates/rollback', [AdminUpdates::class, 'rollback'])->middleware('admin:updates', 'throttle:updates');
    $router->get('/audit', [AdminAudit::class, 'index'])->middleware('admin:audit');
    $router->get('/audit/page/{page:\d+}', [AdminAudit::class, 'index'])->middleware('admin:audit');
});

// Find Match quiz (read-only, cacheable)
$router->get('/api/match', [MatchController::class, 'match'])->withoutTrailingSlash()->middleware('throttle:api')->name('api.match');

// Blog
$router->get('/blog', [BlogController::class, 'index'])->name('blog')->middleware('cache');
$router->get('/blog/page/{page:\d+}', [BlogController::class, 'index'])->name('blog.page')->middleware('cache');
$router->get('/category/{slug:[a-z0-9-]+}', [BlogController::class, 'category'])->name('blog.category')->middleware('cache');
$router->get('/category/{slug:[a-z0-9-]+}/page/{page:\d+}', [BlogController::class, 'category'])->name('blog.category.page')->middleware('cache');

// Forms
$router->post('/contact-us', [ContactController::class, 'submit'])->middleware('throttle:contact')->name('contact.submit');

// Old WordPress URLs
$router->get('/wp-content/uploads/{path:.+}', [LegacyController::class, 'upload'])->withoutTrailingSlash();
$router->get('/feed', [LegacyController::class, 'gone']);
$router->get('/product-category/{path:[a-z0-9-]+(?:/[a-z0-9-]+)*}', [LegacyController::class, 'productCategory']);
$router->get('/wp-login.php', [LegacyController::class, 'login']);
$router->get('/wp-admin', [LegacyController::class, 'login']);

// SEO: robots.txt, sitemaps (same file names as Rank Math), Google Merchant feed (off until enabled)
$router->get('/robots.txt', [SeoController::class, 'robots'])->withoutTrailingSlash();
$router->get('/sitemap_index.xml', [SeoController::class, 'index'])->withoutTrailingSlash()->name('sitemap');
$router->get('/sitemap.xml', [LegacyController::class, 'sitemap'])->withoutTrailingSlash();
$router->get('/wp-sitemap.xml', [LegacyController::class, 'sitemap'])->withoutTrailingSlash();
$router->get('/{type:post|page|product|category|product_cat|store}-sitemap.xml', [SeoController::class, 'sitemap'])->withoutTrailingSlash();
$router->get('/product-feed.xml', [SeoController::class, 'productFeed'])->withoutTrailingSlash();

// Token for forms on pages served from the page cache (see site.js).
$router->get('/csrf-token', [SystemController::class, 'csrfToken'])->withoutTrailingSlash()->middleware('throttle:api');

// Uptime check: no details, no session, no database.
$router->get('/health', [SystemController::class, 'health'])->withoutTrailingSlash()->name('health');

// Local-only environment checks (404 everywhere else).
$router->get('/_status', [SystemController::class, 'status'])->name('status');

// Pages and blog posts share the root URL space, like WordPress. Keep this last.
$router->get('/{slug:[a-z0-9_-]+}', [PageController::class, 'show'])->name('page')->middleware('cache');
