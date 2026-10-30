# The NiCa Collection: new platform

A custom PHP rebuild of thenicacollection.com (WordPress + WooCommerce + Dokan). See [PLAN.md](PLAN.md) for the full plan and phase list.

**Status: Phase 9 done — ready to deploy (see [DEPLOY.md](DEPLOY.md)).** Content, catalog, cart, Stripe checkout and orders (Phases 1–3), plus customer accounts: login by email or username (imported WordPress passwords work and are upgraded), remember-me, lockout after 5 failed attempts, registration as customer or vendor (vendor applications wait for approval), set/reset password by email link, optional two-factor authentication (required for admins), order history, saved addresses, account details and points (10 on signup, 100 points = $1 at checkout). Vendor dashboard at `/dashboard/` (Phase 5): overview with sales chart and setup checklist, products with image upload (re-encoded to WebP) and a Length/Color variation matrix, orders with tracking, completion, packing slips and invoices, earnings ledger with payout requests (minimum $50, PayPal or bank details stored encrypted), Stripe Connect Express onboarding (simulated locally when no Stripe keys are set), store coupons, review replies, store settings and vacation mode. Dokan balances are imported with `import:wp --only=balances` (run after `orders`). Admin panel at `/admin/` (Phase 6, roles admin and staff; admins must turn on two-factor authentication outside local development): dashboard with sales, commission and work queues; orders with per-vendor refunds through Stripe (imported orders are recorded for a manual Stripe refund), cancel/release/complete, notes and CSV export; product moderation, featuring and NiCa Score verification, with the full product editor; vendor approval (first 50 become numbered Premier Partners), suspension, commission and payout overrides, and audited "log in as vendor"; payout queue with encrypted bank/PayPal details, vendor ledgers, manual adjustments and ledger CSV; customers (roles, suspend, password reset link, points); review moderation; all coupons; marketplace settings (commission, tax, shipping, free-shipping amount, points, announcement bar, contact email) stored as overrides of config/*.php; redirects and the 404 log; and an audit log of every sensitive action. Staff can handle orders, products, reviews, customers and stylists; finance, settings, coupons, redirects and the audit log are for admins. Stylist referral program (Phase 7): stylists apply from My Account → Stylist Program (linked from /for-stylists/), admins approve them, and each stylist gets a link (`/?ref=CODE` on any page, remembered 30 days, last click wins) and a code customers can type at checkout. Paid referred orders earn a tier commission (defaults Bronze 5%, Silver 7.5% from $1,000, Gold 10% from $5,000 referred sales in 90 days) on items after discounts, paid by the marketplace out of its own commission and never more than it; it becomes withdrawable when the order part is completed and is taken back on refunds. Stylists' own orders and suspended stylists earn nothing. Stylists request payouts (minimum $25) like vendors; admins pay them from the same payout queue. All program settings are in Admin → Settings. SEO and speed (Phase 8): `/robots.txt`, `/sitemap_index.xml` and the same per-type sitemaps as Rank Math (`post-`, `page-`, `product-`, `category-`, `product_cat-`, plus `store-sitemap.xml`), with product images and private pages left out; breadcrumb, WebSite search, Product, BlogPosting, Organization and FAQ structured data; a Google Merchant feed at `/product-feed.xml` (off until enabled). Non-production copies send `noindex` and block all crawlers in robots.txt. Anonymous visitors get pages from a full-page cache (about 1 ms); visitors with a cart, a login or a query string always get a fresh page, forms on cached pages fetch their CSRF token on submit, and any admin/vendor change or stock change refreshes the cache. CSS/JS are minified automatically into `public/assets/build/` and text responses are gzipped. GA4, Meta Pixel and Klaviyo are ready in Admin → Settings → Integrations; they load only after cookie consent.

## Payments (Stripe)

Without Stripe keys, local mode uses a **test gateway**: checkout shows "Approve / Decline" instead of a card form, so the whole order flow can be tried. It never runs outside `APP_ENV=local`.

To use real Stripe test mode, put your **test** keys in `.env` (Stripe Dashboard → Developers → API keys, with "Test mode" on):

```
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...   # from `stripe listen --forward-to localhost:8080/webhooks/stripe`
```

Then pay with card `4242 4242 4242 4242`, any future date, any CVC. Orders are confirmed when the customer returns from Stripe *and* by the webhook (whichever comes first; both are safe to repeat).

## Scheduled jobs

Run once a minute from cron (sends queued emails, cancels unpaid orders after 60 minutes, clears old guest carts):

```
* * * * * php /path/to/nica/bin/console queue:work
```

## Importing content from WordPress

The old WordPress database is loaded into `nica_wp` (see step 2 below for the database). Then:

```bash
bin/php bin/console import:wp                      # everything: media, categories, posts, pages, faqs, redirects
bin/php bin/console import:wp --only=balances      # Dokan vendor balances/withdrawals into the ledger (after orders)
bin/php bin/console import:wp --only=pages         # re-apply page content after editing database/content/pages/*.php
bin/php bin/console import:wp --only=media --no-download   # media records only
```

Media files are downloaded from the old site with plain GET requests (nothing is written to it) into `public/uploads/`, keeping the WordPress `YYYY/MM/` paths. Old `/wp-content/uploads/...` links redirect there.
Designed pages (About, For Vendors, ...) are lists of section blocks defined in `database/content/pages/<slug>.php`; blocks live in `app/Views/blocks/`.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `openssl`, `sodium`, `curl`, `mbstring`, `gd` (plus `intl` and `zip`, both optional)
- MySQL 8 (or MariaDB 10.6+)
- No Composer and no Node at runtime

## Local setup

1. **PHP extensions**: on this machine they are unpacked into `.devenv/` (no root needed). Run PHP through the wrapper `bin/php`, which loads them. On a machine where you can install them system-wide (`sudo apt install php8.1-curl php8.1-mbstring php8.1-gd php8.1-intl php8.1-zip`), plain `php` works too.
2. **Database** (needs sudo once): creates `nica_local` (new platform) and `nica_wp` (copy of the old WordPress DB for the importer), plus a `nica` user whose password is already generated in `.env`:
   ```bash
   cd ~/Desktop/nica && P=$(grep '^DB_PASSWORD=' .env | cut -d= -f2) && sudo mysql -e "CREATE DATABASE IF NOT EXISTS nica_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS nica_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'nica'@'localhost' IDENTIFIED BY '$P'; GRANT ALL PRIVILEGES ON nica_local.* TO 'nica'@'localhost'; GRANT ALL PRIVILEGES ON nica_wp.* TO 'nica'@'localhost';"
   ```
3. **Migrate and create an admin**:
   ```bash
   bin/php bin/console migrate
   bin/php bin/console user:create --email=you@example.com --role=admin
   ```
4. **Run**: `bin/php bin/console serve`, then open http://localhost:8080. The local-only checklist is at http://localhost:8080/_status/.
5. **Tests**: `bin/php tests/run.php`

The WordPress dump (`*.sql` in the project root) contains customer data. It is git-ignored and readable only by your user; keep it out of any upload or deploy.

## Console

| Command | What it does |
|---|---|
| `serve [--port=8080]` | Development server |
| `migrate`, `migrate:rollback`, `migrate:status` | Database migrations |
| `make:migration <name>` | New migration file |
| `key:generate` | Sets `APP_KEY` in `.env` |
| `user:create --email= --role=` | Create a user, or reset their password / add a role |
| `import:wp [--only=...] [--no-download]` | Import content from the WordPress copy |
| `queue:work` | Send queued emails (cron, every minute) |
| `cache:clear` | Empty `storage/cache` |
| `routes` | List routes |

## Layout

```
app/Core/          framework: App container, Router, Request/Response, Kernel, View, Database,
                   Migrator, Validator, Url (host-based), Security/ (Session, Csrf, Auth,
                   PasswordHasher, RateLimiter)
app/Http/          Controllers/, Middleware/
app/Views/         PHP templates (escape everything with e())
config/            app, database, security (CSP sources, rate limits)
database/migrations/
public/            the only web-exposed folder (index.php, .htaccess, assets/, uploads/)
routes/web.php     all routes; URLs mirror the live site
storage/           cache, logs, sessions, mail (local emails), private (KYC docs)
tests/             php tests/run.php
```

## Domain handling

No domain is hard-coded. Every absolute URL is built from the request's Host header, and only if that host is listed in `TRUSTED_HOSTS` (otherwise the request gets a 400). On a new domain, add it to `TRUSTED_HOSTS`. Behind a CDN such as Hostinger hcdn or Cloudflare, set `TRUSTED_PROXIES` so HTTPS is detected.

## Security built in so far

- Native prepared statements; identifiers validated
- Auto-escaped output helper; strict CSP with per-request nonces, HSTS (production), `X-Frame-Options`, `nosniff`, Referrer and Permissions policies
- CSRF tokens plus an Origin check on every state-changing request
- Hardened sessions: lazy start (no cookie for plain browsing), strict mode, `HttpOnly`, `Secure`, `SameSite=Lax`, idle and absolute timeouts, ID regenerated on login
- Argon2id passwords. Imported WordPress hashes (`$P$`, `$wp$`, bcrypt, md5) are verified and upgraded on login
- Password change invalidates other sessions; MySQL-backed rate limiting; role checks return 404
- Host-header validation, canonical-URL redirects, no PHP execution in `uploads/`, dotfiles blocked

## Going live

The full step-by-step guide for Hostinger (staging rehearsal, cut-over, one-command rollback) is in **[DEPLOY.md](DEPLOY.md)**. Two helper commands:

```bash
php bin/console release   # zip to upload (no .env, no dump, no logs)
php bin/console doctor    # on the server: checks PHP, .env, database, files and cron
php bin/console update    # GitHub updates (also in Admin → Updates); update:install / update:rollback
```

### Speed and SEO reminders

- Writable by PHP: `storage/` (sessions, logs, mail, page cache) and `public/assets/build/` (minified CSS/JS).
- Cron every minute: `php bin/console queue:work` (emails, abandoned orders, expired cache pages).
- After deploying new code: `php bin/console cache:clear`.
- Set `APP_ENV=production` so robots.txt opens up and the `noindex` header disappears; then submit `/sitemap_index.xml` in Google Search Console.

