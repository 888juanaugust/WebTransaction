# Deploying a client's installation

One installation per client, on one server: PHP behind Caddy, PostgreSQL, Redis, a queue
worker under supervisor and the scheduler in cron. This page is the checklist for putting
it into production and keeping it there. Commands assume the code lives in `/srv/erp`
and runs as the `erp` user.

## 1. The server

- Ubuntu 24.04 (or similar), with PHP 8.4 and the extensions `pdo_pgsql`, `intl`,
  `bcmath`, `mbstring`, `gd`, `zip`, `curl` and `redis`; PHP-FPM.
- PostgreSQL 16, Redis 7, Caddy 2, supervisor, cron.
- Node 22 only to build the assets (it does not run in production).
- PostgreSQL and Redis listen on `127.0.0.1` only. Only ports 80 and 443 are open to the
  internet, plus SSH for the administrators.

## 2. The `.env`

Start from `.env.example`. In production:

| Setting | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | `erp:install --fresh` is refused, passwords are checked against known breaches |
| `APP_DEBUG` | `false` | a debug page shows code and settings to whoever hits an error |
| `APP_URL` | `https://erp.example.co.id` | signed print links and emailed links use it |
| `APP_KEY` | generated once (`php artisan key:generate`) | see below |
| `SESSION_SECURE_COOKIE` | `true` | the session cookie travels over HTTPS only |
| `TRUSTED_PROXIES` | `127.0.0.1` | Caddy on the same server; see `.env.example` |
| `DB_*` | the client's database and a user that owns it | |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `redis` | the scheduler's locks and the queue need Redis |
| `MAIL_*` | the client's SMTP | tax invoices are emailed from here (R3) |

**The application key encrypts personal data** (national IDs, tax IDs and bank account
numbers; see [PRIVACY.md](PRIVACY.md)) and the two-factor secrets. Keep a copy of it
outside the server, apart from the database backups: a backup without the key cannot be
read back in full, and a key without the backup is harmless. To change the key, put the
old one in `APP_PREVIOUS_KEYS` so values encrypted with it still read.

## 3. First install

```bash
cd /srv/erp
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan erp:install --no-interaction --company="Example Co" --currency=IDR \
  --admin-email=owner@example.co.id            # no --admin-password: one is made and printed once
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R erp:erp storage bootstrap/cache
```

The administrator chooses their own password at the first sign-in. Then, in Preferences →
Restrictions, turn on **Administrators sign in with a second factor**; each administrator
sets up an authenticator app on their profile page before anything else opens.

## 4. Caddy

```caddy
erp.example.co.id {
    root * /srv/erp/public
    encode zstd gzip
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}
```

Caddy gets and renews the certificate. The application sends its own security headers
(content security policy, frame and content-type rules, referrer policy, HSTS over
HTTPS); do not add conflicting ones in Caddy.

## 5. Queue worker and scheduler

Supervisor, `/etc/supervisor/conf.d/erp-worker.conf`:

```ini
[program:erp-worker]
command=php /srv/erp/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
user=erp
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/srv/erp/storage/logs/worker.log
```

The queue carries re-costing batches and tax-invoice emails. Every job is idempotent.

Cron, for the `erp` user:

```cron
* * * * * cd /srv/erp && php artisan schedule:run >> /dev/null 2>&1
```

The schedule runs recurring transactions every morning and depreciation on the last day
of the month, each once at a time and on one server (Redis holds the lock).

## 6. Backups

Nightly, encrypted, off the server, and restored at least once before the client goes
live and every quarter after.

```bash
# /etc/cron.d/erp-backup: 01:30 every night
30 1 * * * erp pg_dump -Fc erp | age -r age1... > /backup/erp-$(date +\%F).dump.age \
  && tar -C /srv/erp/storage/app -cz . | age -r age1... > /backup/erp-files-$(date +\%F).tar.gz.age \
  && rclone copy /backup remote:erp-backup && find /backup -mtime +7 -delete
```

- `pg_dump -Fc` keeps the whole database, the append-only ledgers and the activity log
  included.
- `storage/app` keeps the tax files, the Coretax PDFs and the uploads that are kept.
- Encrypt with a public key (`age`, `gpg`) whose private half is not on the server.
- Keep the copies off the server (object storage, another provider) for as long as the
  tax retention period requires (ten years for books and tax records).

**Restore test:** on another machine, restore the dump into an empty database
(`pg_restore -d erp_restore …`), point a copy of the code at it with the same `APP_KEY`,
sign in, open a posted invoice, an employee (their national ID shows) and the trial
balance. Write down the date and who did it.

## 7. Updates

```bash
cd /srv/erp
php artisan down
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Migrations only ever add; a backup taken just before is still the way back.

## 8. After every deploy

- `curl -sI https://erp.example.co.id/admin/login` shows `content-security-policy`,
  `strict-transport-security` and `x-frame-options`.
- A printed document opens (a signed link: it fails if `TRUSTED_PROXIES` is wrong).
- `php artisan queue:monitor redis:default` reports the queue is drained.
- `composer audit` and `npm audit` are clean (CI runs both on every push).
