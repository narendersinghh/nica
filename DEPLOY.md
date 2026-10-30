# Deploying The NiCa Collection to Hostinger

This guide moves the site from WordPress to the new app with a short switch-over and an instant way back. Do the whole thing once on a **staging** subdomain first, then repeat the short "go-live" part on the real domain.

Paths below use `u123456789` as the Hostinger account name: replace it with yours (hPanel → Account → Details). `~` means `/home/u123456789`.

---

## 0. What you need

- A Hostinger plan with **SSH access** (Premium, Business or Cloud). Without SSH, use hPanel's File Manager and the "Option B" layout below. Every `php bin/console …` command can also be run as a one-off Cron job.
- PHP **8.2 or 8.3** (8.1 works).
- Two empty databases: one for the new app and one for a **copy** of the WordPress database. Never point the importer at the live WordPress database.
- Stripe **live** keys and a webhook secret (Stripe Dashboard → Developers).
- A mailbox for sending (e.g. `orders@thenicacollection.com`) and its SMTP password.

## 1. PHP settings (hPanel → Advanced → PHP Configuration)

- Version: 8.2 or 8.3.
- Extensions: `pdo_mysql`, `sodium`, `gd`, `mbstring`, `intl`, `curl`, `dom`, `fileinfo`, `zip`, `opcache` (usually on by default).
- Options: `upload_max_filesize = 16M`, `post_max_size = 64M`, `memory_limit = 256M`, `max_execution_time = 120`.

Over SSH, check that `php -v` shows the same version. If it doesn't, call PHP with its full path, e.g. `/opt/alt/php83/usr/bin/php`, and use that path for cron too.

## 2. Databases (hPanel → Databases → MySQL Databases)

1. Create `u123456789_nica` (user `u123456789_nica`) for the new app.
2. Create `u123456789_nicawp` (its own user) for the WordPress copy.
3. In phpMyAdmin, **export** the current WordPress database, then **import** that file into `u123456789_nicawp`. (Or do it over SSH with `mysqldump` → `mysql`.) Delete the `.sql` file from the server afterwards: it contains customer data.

## 3. Upload the code

On your computer, in the project folder:

```bash
php bin/console release
```

This creates `storage/release/nica-<date>.zip` without `.env`, the database dump, logs or media. Upload it to `~/nica/` (a folder **next to** `public_html`, not inside it) and extract it there.

### Point the domain at `nica/public`

**Option A (recommended, SSH).** Keep WordPress's folder as the instant rollback:

```bash
cd ~/domains/thenicacollection.com
mv public_html public_html_wordpress
ln -s ~/nica/public public_html
```

**Option B (no SSH).** Extract the zip directly into `public_html`. The project's root `.htaccess` sends every request into `public/` and blocks everything else. In this case, move WordPress's files into a backup folder first.

## 4. Configure `.env`

```bash
cd ~/nica
cp .env.example .env
chmod 600 .env
php bin/console key:generate
```

Edit `.env`:

| Setting | Value |
|---|---|
| `APP_ENV` | `staging` on the staging subdomain (keeps it out of Google); `production` on the real domain |
| `APP_DEBUG` | `false` |
| `TRUSTED_HOSTS` | `thenicacollection.com,www.thenicacollection.com` (or the staging host) |
| `TRUSTED_PROXIES` | `*` if Hostinger's CDN or Cloudflare is in front |
| `DB_*` | the new app database |
| `WP_DB_DATABASE`, `WP_DB_USERNAME`, `WP_DB_PASSWORD` | the WordPress **copy** |
| `MAIL_DRIVER=smtp`, `MAIL_HOST=smtp.hostinger.com`, `MAIL_PORT=465`, `MAIL_ENCRYPTION=ssl`, `MAIL_USERNAME`/`MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | the sending mailbox |
| `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY` | test keys on staging, live keys on production |
| `STRIPE_WEBHOOK_SECRET` | from step 7 |

## 5. Database tables and content

```bash
php bin/console migrate
cp -r ~/domains/thenicacollection.com/public_html_wordpress/wp-content/uploads/. ~/nica/public/uploads/
php bin/console import:wp --no-download
```

The import is read-only on the WordPress copy and safe to repeat. It brings in pages, posts, media records, redirects, users (old passwords keep working), vendors, products, reviews, coupons, orders, points and Dokan balances. `--no-download` skips fetching images over the web, because you just copied them.

Imported admin accounts keep their WordPress passwords. Each admin must turn on two-factor authentication (My Account → Security) before the admin panel opens on production. To add an admin:

```bash
php bin/console user:create --email=you@example.com --role=admin
```

## 6. Cron (hPanel → Advanced → Cron Jobs)

Every minute:

```
php /home/u123456789/nica/bin/console queue:work
```

This sends the email queue, cancels abandoned unpaid orders, removes old carts and expired cached pages.

## 7. Stripe

1. **Developers → Webhooks → Add endpoint**: `https://<domain>/webhooks/stripe`.
   Events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `account.updated`.
   Also tick **"Listen to events on Connected accounts"** so vendor onboarding updates arrive. Copy the signing secret into `STRIPE_WEBHOOK_SECRET`.
2. **Connect → Settings**: enable Express accounts, and set the branding and the return URL domain to your domain. Vendors then connect from Dashboard → Payout Settings.
3. Payment methods: card and Link, as on the old site (Settings → Payment methods).

## 8. Check everything

```bash
php bin/console doctor
```

Every line should say **PASS** (warnings are fine on staging). Then click through on staging:

- [ ] Home, shop, filters, a product, a collection, a store, blog, a post, quiz, contact form.
- [ ] Register a customer, log in, add to cart, apply a coupon, pay with Stripe test card `4242 4242 4242 4242`, see the thank-you page and the emails.
- [ ] As the vendor: ship the order with tracking, mark it completed, see the earning, request a payout.
- [ ] As admin: approve the payout, refund an order, edit a product, change a setting.
- [ ] Stylist: apply, approve in admin, open `/?ref=CODE`, order, and see the commission.
- [ ] Old URLs: `/wp-content/uploads/...` images, `/?p=<old post id>`, a renamed product slug.

Then clear the test data before going live (step 9 starts from a fresh database).

## 9. Go-live day (about 30 minutes)

1. Put a notice on the WordPress site (or pause checkout) so no new orders arrive during the switch.
2. Export the WordPress database again and import it into the WordPress-copy database (replace the old copy).
3. Start the app from a clean database. In phpMyAdmin, drop all tables in `u123456789_nica`, then:
   ```bash
   php bin/console migrate
   rsync -a ~/domains/thenicacollection.com/public_html_wordpress/wp-content/uploads/ ~/nica/public/uploads/
   php bin/console import:wp --no-download
   ```
4. In `.env`, set `APP_ENV=production`, the real `TRUSTED_HOSTS`, **live** Stripe keys and the live webhook secret.
5. Switch the folder (Option A in step 3), then run:
   ```bash
   php bin/console cache:clear
   php bin/console doctor
   ```
6. Visit the site, place one real low-value order, then refund it from Admin → Orders.
7. Google Search Console: submit `https://thenicacollection.com/sitemap_index.xml`.

## 10. Rollback (seconds)

The WordPress files and database are untouched, so going back is one command:

```bash
cd ~/domains/thenicacollection.com
rm public_html && mv public_html_wordpress public_html
```

Orders placed on the new site during the attempt are kept in its database (Admin → Orders), so nothing is lost.

## 11. After launch

- Look at **Admin → Redirects & 404s** daily for the first week, and redirect any old address people still use.
- **Admin → Dashboard** shows orders on hold, payouts and applications waiting.
- Errors are logged in `~/nica/storage/logs/`.
- Backups: Hostinger's daily backups cover files and databases. Also download a database export before big changes.
- Updating the code later: see "Updates" below.

## Updates (Admin → Updates)

New versions come straight from the GitHub repository. On **Admin → Updates** you see the installed version, the latest **release** and the latest **commit** on `main`, with the list of changes in between. One click:

1. downloads that exact version from GitHub,
2. saves a backup of the current code (the last 3 are kept in `storage/updates/`),
3. shows visitors a short "back in a moment" page while the files are swapped (usually seconds; it switches itself off after 15 minutes at most),
4. applies database changes (migrations) and refreshes the caches.

`.env`, `storage/`, uploaded images, all data, and the PHP-version lines Hostinger writes into `.htaccess` are never changed. **Roll back** restores the previous code with one click.

To ship a version: push to `main` (installable as "latest commit"), or better, create a **release** on GitHub (Releases → Draft a new release → tag e.g. `v1.1.0`) and install that on the live site.

From SSH, the same actions:

```bash
php bin/console update                      # what's installed vs. available
php bin/console update:install --release    # or --commit
php bin/console update:rollback             # if a new version breaks the admin panel
```

Notes:

- Web updates work on sites installed from the **zip** (step 3). A `git clone` checkout is refused on purpose; use `git pull` there.
- A private repository needs `UPDATE_GITHUB_TOKEN` in `.env` (a fine-grained token with read-only "Contents" access). Public repositories need nothing.
- Only admins can update, and every install and rollback is recorded in the audit log.

## Security notes

- Never upload `.env` or any `.sql` file into `public/`. `doctor` checks `.env` permissions.
- `public/uploads/.htaccess` stops anything uploaded from running as code. Keep it.
- Payout bank and PayPal details are encrypted with `APP_KEY`. **Back up `APP_KEY`** (in your password manager): without it, saved payout details and 2FA secrets can't be read.
- Stripe card data never touches the server. Card fields are Stripe's.
