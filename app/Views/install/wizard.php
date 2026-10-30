<?php
/** First-time setup wizard (see InstallController). Standalone page, dashboard styles. */
$env = static fn (string $k, string $d = '') => (string) App\Core\Env::get($k, $d);
$complete = $state['complete'] ?? [];
$status = static function (string $s) use ($complete, $step) {
    if ($s === $step) {
        return 'current';
    }
    return !empty($complete[$s]) ? 'done' : 'todo';
};
$icon = ['PASS' => '✓', 'WARN' => '!', 'FAIL' => '✕'];
?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up <?= e((string) config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/panel.css')) ?>">
</head>
<body class="setup">
<?= $this->insert('partials/panel-icons') ?>
<main class="setup__wrap">
    <header class="setup__head">
        <p class="muted small">First-time setup</p>
        <h1><?= e((string) config('app.name')) ?></h1>
    </header>

    <?php if ($step !== 'unlock' && $step !== 'done'): ?>
    <ol class="setup__steps">
        <?php foreach ($steps as $key => $label): $s = $status($key); ?>
            <li class="is-<?= $s ?>"><?php if ($s === 'done' || $key === $step): ?><a href="<?= e(link_to('/install/') . '?step=' . $key) ?>"><?= e($label) ?></a><?php else: ?><span><?= e($label) ?></span><?php endif ?></li>
        <?php endforeach ?>
    </ol>
    <?php endif ?>

    <?php if ($error): ?><div class="alert alert--error" role="alert"><?= picon('alert') ?><div><?= e($error) ?></div></div><?php endif ?>
    <?php if ($notice): ?><div class="alert alert--success" role="status"><?= picon('check') ?><div><?= e($notice) ?></div></div><?php endif ?>
    <?php if ($envContent): ?>
        <section class="card"><h2>Create the settings file yourself</h2>
            <p class="small">In hPanel → File Manager, create a file named <code>.env</code> in the app folder (next to <code>app/</code> and <code>public/</code>) with exactly this content, then press Continue.</p>
            <textarea class="setup__env" readonly rows="14"><?= e($envContent) ?></textarea>
            <p><a class="btn" href="<?= e(link_to('/install/')) ?>">Continue</a></p></section>
    <?php endif ?>

    <?php if ($step === 'unlock'): ?>
    <section class="card">
        <h2>Prove this is your server</h2>
        <p>To stop anyone else from setting up this site, a one-time code was saved on the server. Open it with <strong>hPanel → File Manager</strong> (or SSH):</p>
        <p><code><?= e($codeFile) ?></code> <span class="muted small">(inside the app folder)</span></p>
        <form method="post" action="<?= e(link_to('/install/unlock')) ?>" class="setup__form">
            <?= csrf_field() ?>
            <div class="fld"><label for="f-code">Setup code</label><input type="text" id="f-code" name="code" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off" autofocus required></div>
            <button class="btn" type="submit">Continue</button>
        </form>
    </section>

    <?php elseif ($step === 'check'): $fails = App\Services\Install\Installer::hasFailures($requirements); ?>
    <section class="card">
        <h2>Server check</h2>
        <ul class="setup__checks">
            <?php foreach ($requirements as [$st, $label, $fix]): ?>
                <li class="is-<?= strtolower($st) ?>"><span class="setup__mark"><?= $icon[$st] ?></span><span><?= e($label) ?><?php if ($st !== 'PASS'): ?><br><span class="muted small"><?= e($fix) ?></span><?php endif ?></span></li>
            <?php endforeach ?>
        </ul>
        <form method="post" action="<?= e(link_to('/install/check')) ?>"><?= csrf_field() ?>
            <button class="btn" type="submit"><?= $fails ? 'Check again' : 'Continue' ?></button>
        </form>
    </section>

    <?php elseif ($step === 'database'): ?>
    <section class="card">
        <h2>Database</h2>
        <p class="small muted">Create an empty MySQL database and user in <strong>hPanel → Databases → MySQL Databases</strong>, then enter them here. If you already uploaded your database, enter that one: existing tables and data are kept.</p>
        <form method="post" action="<?= e(link_to('/install/database')) ?>" class="setup__form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="fld"><label for="f-db">Database name</label><input type="text" id="f-db" name="database" value="<?= e($env('DB_DATABASE') === 'nica' ? '' : $env('DB_DATABASE')) ?>" placeholder="u123456789_nica" required></div>
                <div class="fld"><label for="f-user">Username</label><input type="text" id="f-user" name="username" value="<?= e($env('DB_USERNAME') === 'nica' ? '' : $env('DB_USERNAME')) ?>" placeholder="u123456789_nica" required autocomplete="off"></div>
                <div class="fld"><label for="f-pass">Password</label><input type="password" id="f-pass" name="password" autocomplete="new-password" placeholder="<?= $env('DB_PASSWORD') !== '' ? 'Leave empty to keep the saved one' : '' ?>"></div>
                <div class="fld"><label for="f-host">Host</label><input type="text" id="f-host" name="host" value="<?= e($env('DB_HOST', 'localhost') === '127.0.0.1' ? 'localhost' : $env('DB_HOST', 'localhost')) ?>"><p class="hint">Usually <code>localhost</code> on Hostinger.</p></div>
                <div class="fld"><label for="f-port">Port</label><input type="number" id="f-port" name="port" value="<?= e($env('DB_PORT', '3306')) ?>"></div>
            </div>
            <button class="btn" type="submit">Test and save</button>
        </form>
    </section>

    <?php elseif ($step === 'site'): $hosts = $env('TRUSTED_HOSTS') ?: (str_starts_with($host, 'www.') ? $host . ',' . substr($host, 4) : $host . ',www.' . $host); ?>
    <section class="card">
        <h2>Site settings</h2>
        <form method="post" action="<?= e(link_to('/install/site')) ?>" class="setup__form">
            <?= csrf_field() ?>
            <div class="fld"><label for="f-hosts">Domain(s)</label><input type="text" id="f-hosts" name="hosts" value="<?= e($hosts) ?>" required><p class="hint">Comma separated. The site only answers on these addresses.</p></div>
            <div class="fld"><span class="fld-label">This installation is</span>
                <label class="check"><input type="radio" name="env" value="production"<?= $env('APP_ENV', 'production') !== 'staging' ? ' checked' : '' ?>>The live site <small>(search engines may index it)</small></label>
                <label class="check"><input type="radio" name="env" value="staging"<?= $env('APP_ENV') === 'staging' ? ' checked' : '' ?>>A staging / test copy <small>(hidden from search engines)</small></label>
            </div>
            <label class="check"><input type="checkbox" name="behind_proxy" value="1"<?= $env('TRUSTED_PROXIES') !== '' ? ' checked' : '' ?>>Hostinger CDN or Cloudflare is turned on for this domain</label>

            <h3>Email <span class="muted small">(optional now)</span></h3>
            <p class="small muted">Order confirmations, password links and vendor notices. Use a mailbox from hPanel → Emails. Without it, emails are kept in <code>storage/mail/</code>.</p>
            <div class="form-grid">
                <div class="fld"><label for="f-from">Send emails from</label><input type="email" id="f-from" name="mail_from" value="<?= e($env('MAIL_FROM_ADDRESS')) ?>" placeholder="orders@your-domain.com"></div>
                <div class="fld"><label for="f-mhost">SMTP server</label><input type="text" id="f-mhost" name="mail_host" value="<?= e($env('MAIL_HOST')) ?>" placeholder="smtp.hostinger.com"></div>
                <div class="fld"><label for="f-mpass">Mailbox password</label><input type="password" id="f-mpass" name="mail_password" autocomplete="new-password" placeholder="<?= $env('MAIL_PASSWORD') !== '' ? 'Leave empty to keep the saved one' : '' ?>"></div>
                <div class="fld"><label for="f-mport">Port / security</label><div class="setup__inline"><input type="number" id="f-mport" name="mail_port" value="<?= e($env('MAIL_PORT', '465')) ?>"><select name="mail_encryption" aria-label="Encryption"><option value="ssl"<?= $env('MAIL_ENCRYPTION', 'ssl') === 'ssl' ? ' selected' : '' ?>>SSL</option><option value="tls"<?= $env('MAIL_ENCRYPTION') === 'tls' ? ' selected' : '' ?>>TLS</option></select></div></div>
                <input type="hidden" name="mail_username" value="">
            </div>

            <h3>Stripe <span class="muted small">(optional now)</span></h3>
            <p class="small muted">From Stripe → Developers → API keys. Leave empty to add later in <code>.env</code>; without keys, checkout is off on the live site.</p>
            <div class="form-grid">
                <div class="fld"><label for="f-pk">Publishable key</label><input type="text" id="f-pk" name="stripe_publishable_key" placeholder="<?= $env('STRIPE_PUBLISHABLE_KEY') !== '' ? 'Saved — leave empty to keep' : 'pk_live_…' ?>" autocomplete="off"></div>
                <div class="fld"><label for="f-sk">Secret key</label><input type="password" id="f-sk" name="stripe_secret_key" placeholder="<?= $env('STRIPE_SECRET_KEY') !== '' ? 'Saved — leave empty to keep' : 'sk_live_…' ?>" autocomplete="off"></div>
                <div class="fld full"><label for="f-wh">Webhook signing secret</label><input type="password" id="f-wh" name="stripe_webhook_secret" placeholder="<?= $env('STRIPE_WEBHOOK_SECRET') !== '' ? 'Saved — leave empty to keep' : 'whsec_… (Stripe → Webhooks, endpoint ' . e(url('/webhooks/stripe')) . ')' ?>" autocomplete="off"></div>
            </div>
            <button class="btn" type="submit">Save and continue</button>
        </form>
    </section>

    <?php elseif ($step === 'tables'): ?>
    <section class="card">
        <h2>Create tables</h2>
        <p><?= $state['db']['pending'] ?> group<?= $state['db']['pending'] === 1 ? '' : 's' ?> of tables to create<?= $state['db']['pending'] < count(glob(app()->path('database/migrations') . '/*.php')) ? ' (the others already exist and are kept)' : '' ?>.</p>
        <form method="post" action="<?= e(link_to('/install/tables')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Create tables</button></form>
    </section>

    <?php elseif ($step === 'import'): ?>
    <section class="card">
        <h2>Import from WordPress <span class="muted small">(optional)</span></h2>
        <p class="small">Brings over pages, blog posts, customers (their passwords keep working), vendor stores, products, reviews, coupons, orders and balances from a <strong>copy</strong> of the WordPress database. If you uploaded the new site's database yourself, skip this.</p>
        <ol class="small setup__list">
            <li>In phpMyAdmin, export the WordPress database and import it into a <strong>new, separate</strong> database (never use the live one).</li>
            <li>Copy the WordPress <code>wp-content/uploads</code> folder into this app's <code>public/uploads</code> folder (File Manager → Copy).</li>
        </ol>
        <?php if (!$wpConfigured): ?>
        <form method="post" action="<?= e(link_to('/install/import/config')) ?>" class="setup__form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="fld"><label for="f-wdb">WordPress copy: database</label><input type="text" id="f-wdb" name="wp_database" value="<?= e($env('WP_DB_DATABASE') === 'nica_wp' ? '' : $env('WP_DB_DATABASE')) ?>" required></div>
                <div class="fld"><label for="f-wuser">Username</label><input type="text" id="f-wuser" name="wp_username" value="<?= e($env('WP_DB_USERNAME')) ?>" autocomplete="off"><p class="hint">Empty = same login as the main database.</p></div>
                <div class="fld"><label for="f-wpass">Password</label><input type="password" id="f-wpass" name="wp_password" autocomplete="new-password"></div>
                <div class="fld"><label for="f-wprefix">Table prefix</label><input type="text" id="f-wprefix" name="wp_prefix" value="<?= e($env('WP_DB_PREFIX', 'wp_')) ?>"></div>
            </div>
            <div class="toolbar toolbar--start"><button class="btn" type="submit">Connect</button></div>
        </form>
        <?php else: ?>
        <ul class="setup__checks" data-import-list>
            <?php foreach ($steps as $s): ?><li class="is-todo" data-import-step="<?= e($s) ?>"><span class="setup__mark">•</span><span><?= e($labels[$s]) ?> <span class="muted small" data-import-note></span></span></li><?php endforeach ?>
        </ul>
        <form method="post" action="<?= e(link_to('/install/import/run/')) ?>" data-import-form><?= csrf_field() ?><button class="btn" type="submit" data-import-start>Start import</button></form>
        <?php endif ?>
        <form method="post" action="<?= e(link_to('/install/import/skip')) ?>" class="setup__skip"><?= csrf_field() ?><button class="link-btn" type="submit">Skip this step</button></form>
    </section>

    <?php elseif ($step === 'admin'): ?>
    <section class="card">
        <h2>Admin account</h2>
        <?php if ($state['db']['admins'] > 0): ?>
            <p class="small">This database already has <?= (int) $state['db']['admins'] ?> admin account<?= $state['db']['admins'] === 1 ? '' : 's' ?> (they log in with their existing passwords). You can also create a new one.</p>
            <form method="post" action="<?= e(link_to('/install/admin')) ?>"><?= csrf_field() ?><input type="hidden" name="skip" value="1"><button class="btn btn--ghost" type="submit">Keep the existing admins</button></form>
            <hr class="sep">
        <?php endif ?>
        <form method="post" action="<?= e(link_to('/install/admin')) ?>" class="setup__form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="fld"><label for="f-name">Your name</label><input type="text" id="f-name" name="name" required autocomplete="name"></div>
                <div class="fld"><label for="f-email">Email</label><input type="email" id="f-email" name="email" required autocomplete="email"></div>
                <div class="fld"><label for="f-pw">Password</label><input type="password" id="f-pw" name="password" required minlength="<?= (int) config('security.password.min_length', 10) ?>" autocomplete="new-password"><p class="hint">At least <?= (int) config('security.password.min_length', 10) ?> characters.</p></div>
                <div class="fld"><label for="f-pw2">Password again</label><input type="password" id="f-pw2" name="password_confirmation" required autocomplete="new-password"></div>
            </div>
            <button class="btn" type="submit">Create admin</button>
        </form>
    </section>

    <?php elseif ($step === 'finish'): ?>
    <section class="card">
        <h2>Ready</h2>
        <p>Everything is in place. Finishing turns this setup page off for good.</p>
        <ul class="setup__checks">
            <?php foreach ($steps as $key => $label): if ($key === 'finish') continue; ?><li class="is-pass"><span class="setup__mark">✓</span><span><?= e($label) ?></span></li><?php endforeach ?>
        </ul>
        <form method="post" action="<?= e(link_to('/install/finish')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Finish setup</button></form>
    </section>

    <?php elseif ($step === 'done'): ?>
    <section class="card">
        <h2>🎉 Setup complete</h2>
        <p>The site is live at <a href="<?= e($base) ?>/"><?= e($base) ?>/</a>. This setup page is now switched off.</p>
        <h3>Still to do</h3>
        <ol class="setup__list">
            <li><strong>Cron job</strong> (hPanel → Advanced → Cron Jobs, every minute):<br><code><?= e($cron) ?></code><br><span class="muted small">Sends emails and cancels abandoned unpaid orders.</span></li>
            <?php if ($stripeSet): ?><li><strong>Stripe webhook</strong> (Stripe → Developers → Webhooks): <code><?= e($webhook) ?></code>, events <code>payment_intent.succeeded</code>, <code>payment_intent.payment_failed</code>, <code>account.updated</code>.</li><?php else: ?><li><strong>Stripe keys</strong> are not set yet: add them to <code>.env</code> to take payments (see DEPLOY.md).</li><?php endif ?>
            <?php if (!$mailSet): ?><li><strong>Email</strong> is not set up yet: emails are saved in <code>storage/mail/</code> until SMTP details are added to <code>.env</code>.</li><?php endif ?>
            <li><strong>Two-factor authentication</strong> for every admin (My account → Security)<?= $production ? ': required before the admin panel opens' : '' ?>.</li>
            <li>Back up the <code>APP_KEY</code> line of <code>.env</code> in your password manager.</li>
            <li>Optional: run <code>php bin/console doctor</code> over SSH for a full health check.</li>
        </ol>
        <p><a class="btn" href="<?= e(link_to('/admin/')) ?>">Go to the admin panel<?= $adminEmail ? ' (' . e($adminEmail) . ')' : '' ?></a></p>
    </section>
    <?php endif ?>
</main>
<script src="<?= e(asset('assets/js/panel.js')) ?>" defer></script>
</body>
</html>
