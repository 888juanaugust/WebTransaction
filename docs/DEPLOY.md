# Deploying Central

One server: PHP behind Caddy, PostgreSQL, Redis, a queue worker under supervisor and the
scheduler in cron. The code lives in `/srv/central` and runs as the `central` user. The
kit under `deploy/` does the mechanical parts: `provision.sh` the server, `deploy.sh` a
release, `Caddyfile`, `supervisor/` and `logrotate/` the service files. This page is the
runbook those files implement.

## 1. The server

`bash deploy/provision.sh erp.example.co.id` as root on Ubuntu 24.04: the `central`
user, the firewall (SSH, 80, 443; PostgreSQL and Redis listen on `127.0.0.1` only), PHP
8.4 with `pdo_pgsql intl bcmath mbstring gd zip curl redis` and PHP-FPM, PostgreSQL 16,
Redis 7 (`maxmemory 512mb`, `noeviction`: the queue fails loudly rather than losing
jobs), Caddy 2, supervisor, the cron line, Node 22 for the asset build only. It is
idempotent and stops before what it cannot decide: the database password, `.env`, SSH
hardening (`PermitRootLogin no`, `PasswordAuthentication no` in `sshd_config`, once a
session as `central` is proven to work).

## 2. The `.env`

Start from `.env.example`. In production:

| Setting | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | `erp:install --fresh` is refused, passwords are checked against known breaches |
| `APP_DEBUG` | `false` | a debug page shows code and settings to whoever hits an error |
| `APP_URL` | `https://erp.example.co.id` | signed print links and emailed links use it |
| `APP_KEY` | generated once (`php artisan key:generate`) | see below |
| `SESSION_SECURE_COOKIE` | `true` | the session cookie travels over HTTPS only |
| `TRUSTED_PROXIES` | `127.0.0.1` | Caddy on the same server |
| `DB_*` | the `central` database and user | |
| `QUEUE_CONNECTION`, `CACHE_STORE` | `redis` | the scheduler's locks, the queue and the alert throttle need Redis |
| `MAIL_*` | the company's SMTP | invitations, tax invoices, the aging notice and the health alert |
| `LOG_CHANNEL` | `daily` | 14 days of application log, rotated by itself |
| `BACKUP_DISK` | `s3` | the bucket (section 6) |
| `BACKUP_ENCRYPTION_KEY` | from `php artisan central:backup-key` | kept off the server too |
| `AWS_*` | the bucket's keys; `AWS_ENDPOINT` for R2 or B2 | |
| `OPS_ALERT_EMAIL` | an address besides the administrators, optional | |

**The application key encrypts personal data** (national IDs, tax IDs and bank account
numbers; see [PRIVACY.md](PRIVACY.md)) and the two-factor secrets. Keep a copy outside
the server, apart from the backups and apart from the backup key: a backup without the
application key cannot be read back in full. To change it, put the old one in
`APP_PREVIOUS_KEYS`.

## 3. First install

```bash
cd /srv/central
bash deploy/deploy.sh --first                # composer, the asset build, .env and APP_KEY
# fill .env (section 2), then:
php artisan erp:install --no-interaction --company="PT Example" --currency=IDR \
  --admin-email=owner@example.co.id            # no --admin-password: one is made and printed once
php artisan storage:link                     # the public site's images
php artisan optimize && php artisan filament:optimize
sudo chown -R central:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache
systemctl reload caddy                       # once the domain's A record points here
```

The administrator chooses a password at the first sign-in. Then, in Preferences →
Restrictions, turn on **Administrators sign in with a second factor**; each
administrator sets up an authenticator app on their profile before anything else opens.
Launch Readiness (Settings) lists what is still open; `php artisan central:launch-check`
is the same list on the command line and exits 0 only when nothing is.

## 4. Caddy

`deploy/Caddyfile`, installed by `provision.sh` with the domain filled in. Caddy gets and
renews the certificate. The application sends its own security headers (content policy,
frame and content-type rules, referrer policy, HSTS over HTTPS); nothing conflicting goes
in Caddy.

## 5. Queue worker and scheduler

`deploy/supervisor/central-worker.conf`: two `queue:work redis` processes as `central`,
restarted by `php artisan queue:restart` on every deploy. The queue carries re-costing
batches, tax-invoice and invitation mails, debt notices and backups asked for from the
Operations screen. Every job is idempotent.

Cron, for `central`: `* * * * * cd /srv/central && php artisan schedule:run`. The
schedule, each entry once at a time and on one server (Redis holds the lock):

| When | What |
|---|---|
| every minute | the scheduler's heartbeat (the health check reads it) |
| hourly | `central:health --alert`: a mail per critical incident, re-armed on recovery |
| 00:30 | `central:debt-notices` |
| 01:00 | `central:prune-carts` |
| 01:30 | `central:integrity --notify`: the books against their caches, mailed while a drift stands |
| 02:15 | `central:backup`: dump, encrypt, store, verify, prune |
| 06:00 | `erp:recurring` |
| last day of the month 23:30 | `erp:depreciate` |

## 6. Backups

`php artisan central:backup` dumps the database with `pg_dump`, encrypts the dump and the
kept files under `storage/app/private` (price-list files, tax filings) with libsodium's
secretstream, writes both to `BACKUP_DISK`, reads every artefact back and decrypts it
before recording the run as verified, then prunes: 14 nightly copies and the first of
each month for 12 months, never the newest. The schedule runs it at 02:15; a failed run
is a failed row, shows on the Operations screen and turns the health check critical.

- The disk is the `s3` disk of `config/filesystems.php`: any S3-compatible bucket
  (Cloudflare R2, Backblaze B2, Wasabi, AWS) with `AWS_*` in `.env`. A local disk is not
  a backup of the machine it sits on; the health check says so.
- The key: `php artisan central:backup-key` prints one; put it in `.env` and in a second
  place off the server. Without it the backups are noise; changing it makes the earlier
  ones unreadable.
- Books and tax records are kept ten years: copy the monthly backups out of the bucket's
  retention before they are pruned, or set the bucket's own retention accordingly.

**The restore drill**, before launch and every quarter after:

```bash
sudo -u postgres createdb --owner=central central_restore
php artisan central:restore --into=central_restore --force      # the newest verified run
psql -U central central_restore -c 'select count(*) from sales_invoices'
sudo -u postgres dropdb central_restore
```

Then attest "Restore drilled" on Launch Readiness with the date. The live database is
restored only with `--force` and no `--into`; it must be empty or dropped first, since the
ledgers refuse truncation.

## 7. Updates

Pushing to the `production` branch runs the suite, then `deploy/deploy.sh` on the server
over SSH (`.github/workflows/deploy.yml`; secrets `DEPLOY_HOST`, `DEPLOY_USER`,
`DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`; protect the `production` environment with a
required reviewer). By hand, as `central`:

```bash
cd /srv/central && bash deploy/deploy.sh
```

The script refuses to start with under 1 GB free, puts the maintenance page up, pulls,
installs, builds and checks the build, migrates (migrations only ever add), relinks
`public/storage`, clears the cached config before rebuilding it, restarts the workers,
takes the maintenance page down and prints the launch check. On a failure it lifts the
maintenance page only when the code on disk can still serve.

## 8. After every deploy

- `curl -sI https://erp.example.co.id/admin/login` shows `content-security-policy`,
  `strict-transport-security` and `x-frame-options`; `curl -sI https://erp.example.co.id/`
  shows two `content-security-policy` headers (the site's stricter one).
- A printed document opens (a signed link: it fails if `TRUSTED_PROXIES` is wrong).
- `php artisan central:health` is healthy; `php artisan queue:monitor redis:default`
  reports the queue drained.
- `composer audit` and `npm audit` are clean (CI runs both on every push).

## 9. When something is wrong

The Operations screen (Settings) shows the health checks, the backups and the integrity
findings; `php artisan central:health` is the same on the command line, exit 0, 1 or 2.
The hourly sweep mails every active administrator once per critical incident.

- **Redis down.** Sessions and the cache fail, the queue takes nothing, the alert cannot be
  throttled and so is sent anyway. `systemctl restart redis-server`; the workers reconnect.
- **Scheduler silent** (no heartbeat). `crontab -l -u central` must hold the `schedule:run`
  line; `php artisan schedule:run` by hand shows the error. Nothing nightly ran meanwhile:
  run `central:backup` and `central:integrity` by hand once it is back.
- **Backup failing.** The row's error says which step: `pg_dump` (the database password in
  `.env`), the disk (`AWS_*`, the bucket's policy), the key (`BACKUP_ENCRYPTION_KEY`), the
  read-back (the bucket accepted the write and lost it). Nothing is pruned while the run
  fails.
- **Failed jobs.** `php artisan queue:failed`, then `queue:retry <id>` once the cause is
  fixed; every job is idempotent.
- **Integrity drift.** `php artisan central:integrity` names the row and the difference.
  Find the document that caused it (the activity log, the postings) before touching any
  number; the caches are rebuilt by re-posting the document, never by hand.
- **Vite manifest missing** (every page a 500 after a deploy). The build died, usually out
  of memory: `NODE_OPTIONS=--max-old-space-size=1024 npm run build`, then `php artisan
  optimize && php artisan up`.
- **"Target class [config] does not exist".** A truncated config cache from a deploy
  killed halfway: `rm -f bootstrap/cache/config.php` and run `php artisan optimize` again.
- **A stack trace on a public page.** `APP_ENV` or `APP_DEBUG` is wrong; fix `.env` and
  `php artisan config:cache`.
