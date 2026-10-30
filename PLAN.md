# The NiCa Collection: Rebuild Plan (WordPress → custom PHP)

Status: **draft for review**. Nothing has been built yet. The live site was only read with plain GET requests (pages, sitemaps, public REST endpoints). Nothing on the live site was changed.

---

## 1. What the live site is today (research findings)

| Area | Live implementation |
|---|---|
| Hosting | Hostinger (hPanel, hcdn), PHP 8.3, LiteSpeed/Apache style |
| CMS / builder | WordPress + GeneratePress theme + Elementor Pro (header, footer, page templates) |
| Shop | WooCommerce, 80 products (mostly *variable*: Length × Color), USD |
| Multi-vendor | **Dokan Lite + Dokan Pro** + Dokan Invoice. 12 stores, `/store/{slug}/`, `/store-listing/`, vendor `/dashboard/` |
| Payments | Stripe (`js.stripe.com` loaded on product, cart and checkout pages) |
| Loyalty | Points & Rewards for WooCommerce ("10 points for a successful signup") |
| Find Match quiz | Custom JS app (`hf3-app.js`) + custom REST `hairtype/v1/match`. 4 steps: category → texture (tags) → shade → length, AND logic |
| SEO | Rank Math (meta, schema, `sitemap_index.xml` with post/page/product/category/product_cat sitemaps) |
| Performance | WP Rocket. Homepage HTML is **542 KB**, about 35 scripts including jQuery, Swiper, SmartMenus and moment.js |
| Tracking / marketing | GA4 (gtag + Woo GA integration), Meta Pixel + CAPI, Klaviyo onsite, Google Listings & Ads |
| Other | ShipStation, YaySMTP, Jetpack, Fluent Snippets, Elementor MCP |

**Design tokens (from Elementor globals)**
- Fonts: **Inter** (body/UI), **Cormorant Garamond** (display headings)
- Colors: text `#4D4D4D`, primary `#333333`, dark `#242424` / `#2C211C`, accent gold `#C3A36A` / `#DFB467` / `#FCC25A`, creams `#FAF4EE` `#F6ECE3` `#FAEBE1` `#F7EED9` `#FAEDCA` `#F5E1DA`, mint `#DCF5DA` `#D9F7F5`
- Elementor breakpoints: 1024px (tablet) and 767px (mobile)

**Content inventory**: 21 pages, 38 blog posts, 80 products, 15 product categories (nested), texture tags, 12 vendor stores.

---

## 2. URL map (must stay identical)

| URL | Purpose |
|---|---|
| `/` | Home |
| `/about/` `/contact-us/` `/faqs/` `/testimonials/` | Content pages |
| `/for-customers/` `/for-vendors/` `/for-stylists/` | Audience landing pages |
| `/find-match/` | Hair match quiz |
| `/shop/` | All products (filters, sort, pagination `/shop/page/2/`) |
| `/collection/{cat}/` and `/collection/{cat}/{sub}/[{sub}/]` | Product categories (nested) |
| `/product/{slug}/` | Product page |
| `/store-listing/` and `/store/{vendor-slug}/` | Vendor directory and storefronts |
| `/cart/` `/checkout/` `/thank-you/` | Purchase flow |
| `/my-account/` (+ `orders/`, `view-order/{id}/`, `edit-address/`, `edit-account/`, `lost-password/`, `points/`) | Customer account |
| `/my-orders/` | Customer order list |
| `/dashboard/` (+ sub-pages) | Vendor dashboard |
| `/blog/`, `/{post-slug}/`, `/category/{slug}/` | Blog (posts sit at the root slug, as today) |
| `/terms-and-conditions/` `/privacy-policy/` `/refund_returns/` `/shipping-cancellation/` | Legal |
| `/sitemap_index.xml`, `/{type}-sitemap.xml`, `/robots.txt` | SEO, same filenames as Rank Math |
| `/?s=` | Search |
| `/wp-content/uploads/...` | 301 redirect to the new `/uploads/...` so Google Images and old links keep working |
| `/wp-admin`, `/wp-login.php`, `/feed/` | Redirect to `/admin` or `/my-account/`, or 410 |

A **redirect manager** in the admin covers every other legacy URL. Slugs are imported 1:1 from the live site.

---

## 3. Architecture

**A small, dependency-free PHP framework built for this project.** It needs no Composer at runtime, runs on Hostinger shared hosting (Apache/LiteSpeed `.htaccess`) and also on Nginx.

- **PHP 8.1+** (local has 8.1; the live server runs 8.3), **MySQL 8** through PDO with real prepared statements.
- **Domain-agnostic**: the base URL is taken from the request (scheme + host) and checked against `TRUSTED_HOSTS` in `.env`, which blocks Host-header injection. No domain is ever hard-coded. Canonical URLs, emails, sitemaps, Stripe return URLs and the vendor store-URL prefix all come from it.
- Only `public/` is web-accessible. `.env`, code, storage and logs live outside it.

```
nica/
├─ public/            index.php (front controller), .htaccess, assets/ (hashed, immutable), uploads/
├─ app/
│  ├─ Core/           Router, Request/Response, Container, DB + QueryBuilder, View, Session,
│  │                  Csrf, Validator, Auth, Gate (policies), Cache, Mailer (SMTP), Queue, RateLimiter
│  ├─ Http/
│  │  ├─ Controllers/ Site/, Shop/, Account/, Vendor/, Admin/, Api/
│  │  └─ Middleware/  SecurityHeaders, Csrf, Auth, Role, RateLimit, PageCache, Maintenance
│  ├─ Models/         Product, Variant, Category, Vendor, Order, SubOrder, Payout, Post, ...
│  ├─ Services/       Cart, Checkout, Pricing, Tax, Shipping, Stripe, Commission, Points,
│  │                  Referral, Search, Seo, Sitemap, Image, Importer
│  └─ Views/          layouts/, partials/ (header, footer, product-card), site/, shop/, account/, vendor/, admin/
├─ database/          migrations/, seeds/
├─ bin/console        migrate, seed, import:live, cache:clear, queue:work, sitemap:build, user:create
├─ storage/           cache/, logs/, sessions/, private-uploads/ (vendor KYC docs)
├─ tests/             unit + HTTP feature tests (small built-in runner, no PHPUnit required)
└─ .env.example
```

**Stripe** is called directly over HTTPS with cURL, without the SDK: PaymentIntents, Payment Element, webhooks verified with an HMAC signature, and idempotency keys. That keeps the code small and auditable.

---

## 4. Performance targets and how we hit them

| Target | How |
|---|---|
| Homepage HTML under 60 KB (live is 542 KB), JS under 25 KB, no jQuery | Hand-written semantic templates; small vanilla JS modules (menu, carousel, cart drawer, variant picker, quiz) |
| LCP < 1.5 s, CLS ≈ 0, TTFB < 100 ms (cached) | Full-page cache for anonymous visitors (file cache, invalidated per product, vendor or page); cart count loaded by a tiny JSON call |
| Fonts | Self-hosted Inter + Cormorant Garamond as subset WOFF2 with `preload` and `font-display: swap` |
| Images | Resized on upload into WebP (+ AVIF when available) at several widths, `srcset`, width/height set, lazy-loaded below the fold |
| CSS | One tokenized stylesheet (about 30 KB), critical CSS inlined, hashed filenames with a one-year immutable cache |
| DB | Indexed lookups (slug, category, vendor, status, price), no N+1 queries, OPcache on |
| Third-party scripts | GA4, Meta and Klaviyo load after consent or interaction, never block render |

---

## 5. Security measures

- **Injection / XSS**: PDO prepared statements everywhere; every template variable escaped with `e()`; rich text (blog, product descriptions) passed through an allow-list HTML sanitizer.
- **CSRF** token on every form and on state-changing AJAX calls; `SameSite=Lax` cookies.
- **Passwords**: Argon2id. Imported WordPress hashes (phpass/bcrypt) are verified once and **upgraded automatically on login**, so no one has to reset a password.
- **Sessions**: `Secure`, `HttpOnly`, ID regenerated on login and on privilege change, idle and absolute timeouts.
- **Brute force**: rate limits on login, registration, password reset, contact form and quiz API; temporary lockout; honeypot fields, with optional Cloudflare Turnstile.
- **2FA (TOTP)** required for admins and optional for vendors.
- **Authorization**: role + policy checks (a vendor can only see or change their own products, orders and payouts), enforced server-side on every route.
- **Payments**: totals always recalculated on the server; Stripe webhook signatures verified; idempotent order creation; card data never touches our server (Stripe Payment Element).
- **Uploads**: MIME sniffing, extension allow-list, images re-encoded (strips payloads and EXIF), random filenames, PHP execution disabled in `/uploads`, KYC documents stored outside the web root.
- **Headers**: strict CSP with nonces, HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'`.
- **Password reset tokens** stored hashed, single-use, expire after 60 minutes.
- **Audit log** of admin and vendor actions (prices, payouts, refunds, role changes).
- Errors are logged and never shown in production; `.env` is outside the web root; dependency-free core means no supply-chain surface.

---

## 6. Data model (main tables)

`users` (one account can hold several roles: customer, vendor, stylist, staff, admin) · `vendors` (store name/slug, logo, banner, bio, policies, address, socials, status *pending/approved/suspended/vacation*, `is_premier_partner`, `premier_number`, NiCa Score, commission override, payout details / Stripe account) · `vendor_documents`
`products` (vendor, status *draft/pending_review/published/rejected*, NiCa Score verified flag, SEO fields) · `product_variants` (SKU, price, sale price + schedule, stock, weight) · `attributes` / `attribute_values` (Length, Color, …) · `product_images` · `categories` (nested, path-based slugs) · `tags` (textures) · `reviews` (verified-buyer flag, moderation)
`carts` / `cart_items` · `orders` (customer-facing) · `vendor_orders` (one per vendor, Dokan-style split) · `order_items` · `payments` · `refunds` · `shipments` (carrier, tracking)
`commission_rules` (global → category → vendor, % and/or flat) · `vendor_ledger` (sale, commission, refund, payout, adjustment) · `payout_requests`
`coupons` (platform or vendor-scoped) · `shipping_zones` / `shipping_methods` (per vendor, plus the platform rule "free shipping over $200") · `tax_rates`
`points_ledger` · `referrals` / `referral_clicks` / `stylist_tiers` · `wishlists` · `saved_matches`
`posts`, `post_categories`, `pages` (section-based content blocks), `testimonials`, `faqs`, `media`, `menus`, `redirects`, `seo_meta`, `settings`, `email_templates`, `email_queue`, `audit_log`, `contact_messages`

---

## 7. Front end: same design, rebuilt cleanly

1. Save the rendered live pages (HTML, CSS, screenshots at 1440 / 1024 / 390 px) as the reference.
2. Rebuild each template as clean semantic HTML with one CSS file that uses the exact Elementor tokens, spacing, radii, shadows and breakpoints.
3. Recreate the interactive parts without libraries: announcement bar, sticky header + mobile menu, hero video "Play", trust marquee, category tiles, "Shop by hair type", product cards ("By: {vendor}", sale price), before/after carousel, cart drawer, product gallery, variant selector (Length × Color with live price and stock), tabs (Description / Shipping / Additional info / Reviews), share links.
4. **Find Match quiz**: same 4 steps, images and AND logic, powered by a new `/api/match` endpoint (a compatibility alias keeps `/wp-json/hairtype/v1/match` working), quiz options editable in the admin, and the result can be saved to the customer's account.
5. Check desktop, tablet and mobile side by side against the live site in the browser.

Content (text, images, products, posts, categories, vendors) is **imported**, not retyped, so the copy stays the same.

---

## 8. Back ends: new UI and UX

All three panels share one clean component system: sidebar navigation, data tables with search/filter/bulk actions, slide-over editors, toasts, and the NiCa palette. They work on mobile.

### Admin (`/admin`)
- **Dashboard**: GMV, orders, AOV, commission earned, pending approvals, low stock, payout queue.
- **Vendors**: onboarding queue (documents, approve/reject with reason), Premier Partner badge (first 50, numbered automatically), NiCa Score, commission override, suspend/vacation, "log in as vendor" (audited).
- **Products**: moderation queue (pending review → publish/reject with notes), NiCa Score verification, bulk edit, CSV import/export.
- **Orders**: unified view with vendor sub-orders, refunds (Stripe), disputes / buyer-protection claims, notes, invoices.
- **Finance**: commission rules, vendor ledger, payout requests/batches, CSV export for accounting.
- **Marketing**: coupons, points & rewards rules, stylist tiers & commissions, quiz editor.
- **Content**: pages (section editor), blog, testimonials, FAQs, menus, media library.
- **SEO**: per-item meta, schema defaults, redirects, sitemap settings, 404 log (shows URLs that need a redirect).
- **Settings**: Stripe keys (test/live switch), SMTP, tax, shipping, store info, integrations (GA4, Meta, Klaviyo), staff roles & permissions.
- **Audit log**.

### Vendor dashboard (`/dashboard/`)
- Onboarding checklist (profile → payout → shipping → first product → submit for review).
- Overview: sales, orders to ship, earnings balance, top products.
- Products: variant matrix editor (generate Length × Color combinations, bulk price/stock), image uploader, duplicate product, submit for review.
- Orders: ship with tracking, packing slip and invoice PDF (replaces Dokan Invoice), order notes.
- Earnings & withdrawals (ledger + payout requests), store coupons, shipping zones, reviews (reply), store settings, vacation mode, analytics.

### Customer account (`/my-account/`)
Orders and tracking, reorder, addresses, account details, points balance and history, wishlist, saved match results, return/claim requests.

### Stylist program (new; today `/for-stylists/` is marketing only)
Stylist sign-up, personal referral link/code (cookie attribution), clicks → orders → commissions dashboard, tier progression, and payouts through the same ledger.

---

## 9. Checkout and Stripe flow

1. The cart is kept server-side (session for guests, merged into the account on login). Prices, stock, coupons, shipping and tax are recalculated on every change.
2. Checkout is a single page: contact → shipping → shipping method per vendor → payment (Stripe **Payment Element**: cards, Apple Pay, Google Pay, Link), plus Express Checkout buttons on the product and cart pages, as today.
3. The server creates the PaymentIntent with the final amount and an idempotency key.
4. Stripe webhook `payment_intent.succeeded` → order marked paid → split into vendor sub-orders → stock reduced → vendor ledger credited (amount minus commission) → points awarded → stylist commission recorded → emails queued. The `/thank-you/` page only reads that status; it never trusts the browser.
5. Refunds issued from the admin call the Stripe Refund API and write reversing ledger entries automatically.

---

## 10. Migration (read-only from live)

- **Public data**, pulled with `bin/console import:live --source=https://…` using only GET requests: products + variations + images, categories, tags, pages, posts, media, vendor stores. Images are downloaded and re-encoded locally.
- **Private data** (users, password hashes, orders, vendor payout settings, reviews, points) is **not** publicly reachable. For that I'll need a **database export** (SQL dump from hPanel → phpMyAdmin, or WooCommerce export CSVs), which the importer maps. Without it, the local copy starts with no customers or orders.

---

## 11. Local setup (in this folder)

- Run with `php -S localhost:8080 -t public public/index.php` and a local MySQL database `nica_local`.
- `.env` holds DB credentials, `APP_ENV=local`, `TRUSTED_HOSTS=localhost,127.0.0.1`, and **Stripe test keys**. Webhooks are tested with the Stripe CLI or a "simulate webhook" button in local mode.
- Emails are written to `storage/mail/` locally instead of being sent.

**PHP extensions missing on this machine** (needed before building): `curl` (Stripe), `mbstring`, `gd` (image resizing), `intl` (optional), `zip` (optional). One command installs them:
```bash
sudo apt install php8.1-curl php8.1-mbstring php8.1-gd php8.1-intl php8.1-zip
```

---

## 12. Build phases

| # | Phase | Result you can test |
|---|---|---|
| 0 | Core framework, config, migrations, security middleware, local server | `localhost:8080` serves a page; `bin/console migrate` works |
| 1 | Live content importer + design system + header/footer + static pages, blog, legal | Home, About, FAQs, Testimonials, For-* pages and blog match the live site |
| 2 | Catalog: shop, collections, product page, search, filters, store listing, storefronts, Find Match | Browse and filter all 80 products |
| 3 | Cart, checkout, Stripe (test mode), orders, emails, thank-you | Full test purchase with a Stripe test card |
| 4 | Customer accounts, registration (customer/vendor), points | Sign up, log in, see orders and points |
| 5 | Vendor dashboard | Vendor adds a product, ships an order, requests a payout |
| 6 | Admin panel | Approve vendors/products, refund, run payouts, edit content and SEO |
| 7 | Stylist referral program | Referral link → order → commission |
| 8 | SEO (meta, schema, sitemaps, redirects), analytics, performance pass | Lighthouse 95+; sitemaps match the live URL set |
| 9 | QA: visual comparison with live, security checks, test suite, Hostinger deployment guide | Ready to go live |

---

## 13. Decisions (answered 2026-10-30)

1. **Vendor payouts: both.** A vendor with a connected Stripe account (Stripe Connect) gets their share split automatically per order. A vendor without one accrues a ledger balance, paid out manually once it reaches a minimum payout threshold (set in the admin, with optional per-vendor override).
2. **Data:** the owner will provide a database export. The importer maps users (with password-hash upgrade), orders, vendors, reviews and points from it.
3. **Integrations:** GA4, Meta Pixel/CAPI, Klaviyo, ShipStation and Google Listings & Ads get settings slots and hooks in the code, but stay **disconnected** for now.
4. **Commission:** same rules as the current Dokan setup, read from the database export (`dokan_*` options and per-vendor meta).
5. **Tax:** same setup as current WooCommerce, read from the database export (`woocommerce_*` tax options and the `wc_tax_rates` table).

### Current settings found in the database export (`the WordPress database export (.sql)`, MariaDB 11.8, 2026-10-30)

| Setting | Live value | New platform |
|---|---|---|
| Admin commission | **15%** global (`commission_type` fixed → percentage, no flat fee). No per-vendor overrides set. Past orders were recorded at 10% (49) and 15% (16) | Global 15%, with category/vendor overrides available. Imported orders keep their recorded rate |
| Shipping & tax recipient | Both go to the **vendor** (`shipping_fee_recipient`, `tax_fee_recipient` = seller) | Same: vendor ledger gets item net + shipping + tax |
| Sales tax | Enabled. Prices exclude tax. One rate: **9.75%**, all locations, not applied to shipping, based on the shipping address | Same rule, editable tax-rate table |
| Currency / base | USD, symbol on the left, store base US:TN | Same |
| New vendors | Need manual approval before selling. Products publish immediately | Same defaults; product review queue available as an option |
| Vendor payouts | Manual disbursement via **PayPal** or **bank**, withdraw limit $0, only from **completed** orders | Manual payouts (PayPal/bank) with an admin-set minimum threshold, plus automatic Stripe Connect split for connected vendors |
| Stripe | WooCommerce Stripe gateway, live mode, Payment Element (card + Link), Express Checkout on checkout, saved cards on, Apple Pay domain verified | Same methods; keys entered fresh in settings (not copied from the dump) |
| Points | 10 points on signup; points redeemable at checkout, 100 points = $1; referral points off | Same |
| Store URL base | `/store/` | Same |
