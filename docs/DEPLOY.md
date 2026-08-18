# Deploying to a Hostinger VPS

From a bare server to taking real money, in the order it has to happen.

`REQUIREMENTS.md` is the inventory — versions, PHP extensions, why each
dependency is there. This is the procedure, and it does not repeat that list.
`docs/BACKUP.md` is the backup runbook. Read all three before launch day, not
during it.

---

## 1. Which plan

**KVM 2.** Not because of load — the business does roughly 5,000 transactions a
year, about twenty working-day orders, which is nothing. It is the shape of the
stack that decides.

Shared hosting cannot run this at all, and the reason is worth being precise
about rather than taking on faith:

| What this needs | Shared hosting |
|---|---|
| PostgreSQL 16 | MySQL only |
| Redis | no |
| Supervisor-managed workers | no |
| `pg_dump`, a cron of your own, root | no |

So it is a VPS whatever the transaction count. Between the KVM tiers, two
things on this system are memory- and CPU-hungry in bursts, and both are
invisible until they collide with someone using the panel:

- The price list import reads a 1,400-row supplier workbook through
  PhpSpreadsheet in a single queued job.
- The nightly `pg_dump`, its encryption, and the upload all run on this box.

On one vCPU those compete with web requests. Two vCPU and 8 GB is the cheap
insurance, and the difference in price is roughly a couple of dollars a month.

**Datacenter: Singapore.** There is no Jakarta region. Latency from Jakarta is
in the tens of milliseconds and does not matter for this workload.

> Two things to settle before you commit, neither of them technical:
>
> - `CLAUDE.md` says "Single VPS, Jakarta". Singapore means customer data sits
>   outside Indonesia. PP 71/2019 permits that for a private-scope PSE, but you
>   are registering as one — put the question to whoever handles the OSS filing
>   rather than taking this file's word for it.
> - **Hostinger's snapshots are not your backup.** A snapshot of this VPS dies
>   with this VPS. `docs/BACKUP.md` requires encrypted artefacts stored
>   somewhere else entirely, and that requirement is not satisfied by anything
>   in the hosting panel.

---

## 1b. What to pick in the setup wizard

Hostinger asks four things when the VPS is created. The answers are all "the
plain one", and each for a specific reason.

### Operating system: **Ubuntu 24.04 LTS**, on its own

Not because it is better than Debian — because it is what everything below is
written against. `ondrej/php` publishes PHP 8.4 for it, `postgresql-16`,
`supervisor` and `caddy` are all one `apt install` away, and the LTS means
security updates until 2029 without a distribution upgrade in the middle of a
trading year.

### Control panel: **none**

The wizard will offer Ubuntu bundled with hPanel, CyberPanel, Plesk or cPanel.
Take none of them, and this is the one choice on the page that would actively
hurt:

- They are built for **PHP + MySQL + Apache/LiteSpeed** shared-hosting shapes.
  This app requires PostgreSQL — not as a preference, but because the schema
  uses `SELECT … FOR UPDATE` row locks for stock reservation, partial unique
  indexes and `jsonb`. A panel will not manage that database for you, so you
  end up running Postgres by hand *anyway*, next to a MySQL the panel insists
  on keeping.
- They own the web server config and the TLS certificates. Caddy is doing that
  here, and two things generating vhosts is how you get an outage nobody can
  explain.
- Every panel is a second public admin login on the same box as your customers'
  credit data. There is no version of that which improves security.

The whole of section 2 below is what a panel would have done for you, and it is
about forty lines of shell.

### Application template: **none**

The one-click list (WordPress, n8n, and so on) installs something you would then
have to remove. Nothing there is this app.

### Docker: **no** — with one honest exception

There is a `docker-compose.yml` in this repository, but read where it lives:
`docs/odoo-evaluation/`. It exists to stand Odoo 19 up for the "why not just use
Odoo" comparison. **It has nothing to do with running this application**, and
deploying it would give you Odoo, not this.

**Nothing in this application prevents it**, and it is worth being exact about
that rather than hand-waving. In particular the backup system does *not* care:
`DatabaseDumper` runs `pg_dump` and `psql` over TCP using `--host`/`--port` from
the connection config with `PGPASSWORD` in the environment, and both binaries
are configurable through `BACKUP_PG_DUMP` and `BACKUP_PSQL`. Put
`postgresql-client-16` in the PHP image, point `DB_HOST` at the database
service, and dumps, restores and the restore drill all work untouched.

The case for plain Ubuntu here is smaller than that, and honest about its size:

- Supervisor, cron and Caddy are already the deployment model, written down and
  proven. Containerising means expressing all three a second way for no change
  in behaviour.
- One app, one box, one developer. Docker earns its keep across several
  services with conflicting dependencies, or one image deployed to more than one
  machine. Neither is true yet.
- The one real footgun: `storage/app` holds every uploaded price list **forever**
  by project rule. Under Docker that must be a named volume. Get it wrong and a
  `docker compose down -v` destroys the raw files behind every published price
  version.

What Docker genuinely buys, if you want it: a build that does not depend on
`ondrej/php` still publishing what it published last year, rollback by pointing
at the previous image tag, and one command to stand the whole thing up on a new
machine.

Two points where it stops being a preference and becomes the right answer:
**running Odoo, Metabase or anything else alongside this on the same VPS** — put
those in containers whatever you do with this app — and **more than one
environment**, such as a staging box that has to match production exactly. The
first is also roughly where KVM 2 stops being enough.

### Daily backup add-on: **yes, and it does not replace `docs/BACKUP.md`**

These are two different products for two different disasters, and the mistake to
avoid is thinking either covers both:

| | Hostinger snapshot | `php artisan backup:run` |
|---|---|---|
| Restores | the whole machine | the database |
| Covers | packages, configs, Caddy, supervisor — everything not in git | orders, ledgers, prices |
| Lives | in your Hostinger account | wherever `BACKUP_DISK` points, off this machine |
| Encrypted with a key you hold | no | yes |
| Granularity | the entire VPS | one database, restorable into a scratch copy |

Take the paid daily upgrade if it is a few dollars a month — rebuilding a server
from a snapshot is minutes, and rebuilding it from this runbook is an afternoon
you will not want to spend under pressure.

But it improves nothing about your data risk. The nightly `pg_dump` runs at
02:15, so worst case is one trading day of orders; a daily snapshot has exactly
the same granularity. And a snapshot cannot help you at all in the two cases
that actually happen to small businesses: a table dropped by mistake at 3pm —
where you want one database restored into a scratch copy, not the whole machine
rolled back over every order taken since — and losing access to the hosting
account itself, which takes its own backups with it.

So: buy it for machine recovery, keep the encrypted off-box dumps for the data,
and do not let the presence of one excuse skipping the other.

---

## 2. First hour on a bare server

Ubuntu 24.04 LTS. Everything below as root unless it says otherwise.

### A user that is not root

```bash
adduser deploy
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
```

Then, in `/etc/ssh/sshd_config`: `PermitRootLogin no` and
`PasswordAuthentication no`. `systemctl restart ssh`. Keep your current session
open until you have proved a new one works — locking yourself out of a fresh
VPS is recoverable, but only through the provider's console.

### Firewall

```bash
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw enable
```

Postgres and Redis are **not** in that list on purpose. Both bind to localhost
and nothing outside this machine has any business reaching them.

### Packages

```bash
add-apt-repository ppa:ondrej/php && apt update
apt install -y \
  php8.4-fpm php8.4-pgsql php8.4-redis php8.4-mbstring php8.4-xml php8.4-zip \
  php8.4-gd php8.4-intl php8.4-curl php8.4-bcmath \
  postgresql-16 postgresql-client-16 redis-server supervisor caddy git unzip
```

`REQUIREMENTS.md §2` explains what each extension is actually reached for. The
`gd`, `zip` and `xml` ones are all the price list importer — an `.xlsx` is a zip
of XML, and dropping any of them turns the import into a runtime failure on the
one screen that matters most to Sales.

Composer and Node:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt install -y nodejs
```

---

## 3. Database and Redis

```bash
sudo -u postgres createuser --pwprompt webtransaction
sudo -u postgres createdb --owner=webtransaction webtransaction
```

Redis needs one change from the default. In `/etc/redis/redis.conf`:

```
maxmemory 512mb
maxmemory-policy noeviction
```

**`noeviction`, not `allkeys-lru`.** Redis holds the queue as well as the cache
here. Under an eviction policy a memory spike silently drops queued jobs, and
the job it drops might be the one that posts a customer's payment. Failing loudly
when Redis is full is the correct behaviour; losing money quietly is not.

```bash
systemctl enable --now redis-server postgresql
```

---

## 4. The application

As `deploy`:

```bash
sudo mkdir -p /var/www/webtransaction && sudo chown deploy:deploy /var/www/webtransaction
git clone <repo> /var/www/webtransaction
cd /var/www/webtransaction

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

### `.env` values that differ from development

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://portal.example.co.id      # the real domain, https, no trailing slash

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=webtransaction
DB_USERNAME=webtransaction
DB_PASSWORD=…

QUEUE_CONNECTION=redis
CACHE_STORE=redis

XENDIT_SECRET_KEY=xnd_production_…
XENDIT_CALLBACK_TOKEN=…
BACKUP_ENCRYPTION_KEY=…                    # php artisan backup:key
BACKUP_DISK=…                              # NOT this machine — see docs/BACKUP.md
```

`APP_DEBUG=false` is not a style preference. A stack trace on an exception page
carries the database credentials and the Xendit secret out to whoever triggered
it.

`APP_URL` must be the real https address. It is what invoice PDFs, portal links
and `php artisan xendit:verify` all build from.

```bash
php artisan migrate --force
php artisan filament:assets
php artisan optimize
php artisan filament:optimize

sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

Do **not** run `DemoSeeder`. It refuses under `APP_ENV=production` anyway — it
writes invented prices into append-only ledgers and there is no clean way back
out — but do not go looking for a way around that refusal.

---

## 5. Caddy

`/etc/caddy/Caddyfile`:

```
portal.example.co.id {
    root * /var/www/webtransaction/public
    encode zstd gzip

    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server

    # The panel and the importer both move real files: a 1,400-row price list
    # and the faktur pajak export. The default cap rejects them.
    request_body {
        max_size 32MB
    }

    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
        Referrer-Policy "strict-origin-when-cross-origin"
    }

    log {
        output file /var/log/caddy/webtransaction.log
    }
}
```

Point the domain's A record at the VPS first — Caddy obtains the certificate on
first request and cannot do so until DNS resolves. Then
`systemctl reload caddy`.

**The one thing to be careful about here:** whatever you add to this file
later, it must not touch `POST /webhooks/xendit`. That route is authenticated by
the `x-callback-token` *header*, so any proxy layer, cache rule or WAF that
strips unknown headers turns every payment callback into a 401 — and a 401
there is invisible from every screen in the application. The money still lands
in the bank; the order simply never becomes `paid`, forever.

---

## 6. Queue workers and the scheduler

Both are required for correctness, not convenience — `REQUIREMENTS.md §6` has
the table of what breaks without them. The short version: without the queue, no
payment is ever posted; without the scheduler, stock stays fenced by orders
nobody paid for, and a payment whose worker died is never recovered.

`/etc/supervisor/conf.d/webtransaction-worker.conf`:

```ini
[program:webtransaction-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/webtransaction/artisan queue:work --queue=default --tries=5 --max-time=3600
directory=/var/www/webtransaction
autostart=true
autorestart=true
user=deploy
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/webtransaction-worker.log
stopwaitsecs=3600
```

`stopwaitsecs=3600` matters more than it looks: it lets a worker finish the job
in its hands before supervisor kills it. The callback job is crash-safe either
way — it commits the payment entry and the done-marker in one transaction — but
there is no reason to make the sweeper do work a graceful stop avoids.

Two processes, not ten. Twenty orders a day does not need a worker pool; two
means one can be busy with a slow price-list import while the other still picks
up a payment callback.

```bash
supervisorctl reread && supervisorctl update && supervisorctl start webtransaction-worker:*
```

The scheduler, as `deploy`'s crontab:

```cron
* * * * * cd /var/www/webtransaction && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Xendit

The code side is already done — `AppServiceProvider` swaps
`LocalVirtualAccountGateway` for `XenditVirtualAccountGateway` as soon as
`XENDIT_SECRET_KEY` is filled, and nothing else needs changing. What is left is
the dashboard side and proving it.

In the Xendit dashboard:

1. **Settings → Developers → API keys.** Create a secret key with write access
   to Fixed Virtual Accounts. It will start `xnd_production_`.
2. **Settings → Developers → Callbacks.** Set the *Fixed Virtual Account paid*
   callback URL to `https://portal.example.co.id/webhooks/xendit`, and copy the
   callback verification token into `XENDIT_CALLBACK_TOKEN`.
3. Activate the banks you actually want to accept. `config/xendit.php` lists
   BCA, BNI, BRI, Mandiri and Permata; a bank that is not enabled on the
   account is refused at provisioning time.

Then, on the server:

```bash
php artisan config:clear && php artisan config:cache
php artisan xendit:verify
```

That command exists because the test suite cannot tell you any of what actually
breaks on a first deployment. It checks, in order:

| Stage | What a failure means |
|---|---|
| Konfigurasi | Key missing, or you cannot tell sandbox from production by looking |
| Binding | The key is in `.env` but not visible to the running process — nearly always a stale `config:cache` |
| API | The key is refused, or the bank is not enabled |
| VA lokal | A virtual account minted by the *local* gateway survived into production. No bank has ever heard of that number |
| Callback | Xendit's callback cannot reach this box: DNS, TLS, Caddy, a stripped header |
| Antrean | The callback arrived, was stored, and no worker picked it up |

The callback stage posts a **zero-amount** event to the app's own public URL. It
goes out through DNS, TLS and Caddy and back in, which is the entire point — an
in-process call proves nothing about the path a real callback takes. Zero
amount because `ProcessXenditCallback` returns early on those without writing a
payment entry: what needs proving is that the request arrives and gets picked
up, not that we can fabricate a payment.

On production keys the callback stage **refuses to run** and says so. The event
id is the idempotency key for real money, and inventing one on a live system
puts a row into the table that decides what has already been handled.

---

## 8. Backups

`docs/BACKUP.md` is the runbook; two things belong here because they are the
ones people skip on a VPS:

- `BACKUP_DISK` must not be this machine. A backup on the server it protects is
  a copy, not a backup.
- **Practise the restore before launch**, not after the first incident:
  `php artisan backup:restore --into=a_scratch_database`.

---

## 9. Before the first customer logs in

Everything below is a launch blocker, and only the first two are code.

- [ ] `php artisan xendit:verify` — every stage green
- [ ] `php artisan backup:restore --into=scratch` — actually restored, not just written
- [ ] Real company details replacing the placeholders in `config/company.php`
- [ ] PSE Lingkup Privat registration via OSS → PB-UMKU
- [ ] A lawyer's reading of the two legal pages — they are drafted, not reviewed
- [ ] The two commercial values marked `>>> PUTUSKAN` in `config/legal.php`:
      the late-payment rate and the claim window
- [ ] Staff passwords changed from the seeded `password`
- [ ] One real order taken end to end by staff, on the real system, before any
      buyer has a login — that is what build order phase 1 is for

---

## 10. Routine deploys after that

```bash
cd /var/www/webtransaction
php artisan down --render="errors::503"

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize && php artisan filament:optimize
php artisan queue:restart

php artisan up
```

`queue:restart` is not optional. Workers are long-lived processes holding the
old code in memory; without it, a deploy that changes a job leaves the previous
version running until something else restarts it.

Migrations are additive by project rule, so `migrate --force` on a live database
is safe — nothing shipped is ever edited. That rule is what makes this list this
short, and it is worth keeping.

---

## When something is wrong

The three failures that actually happen, and what each looks like:

**Staff cannot log in, panel returns 500.** Redis is down. It is on the login
path — Filament's rate limiter uses the cache. `systemctl status redis-server`.

**Orders reach `awaiting_payment` and never move, but the money is in the
bank.** The callback is not arriving or not being processed. Run
`php artisan xendit:verify`; it distinguishes the three causes. Nothing recovers
from this on its own, because Xendit already received a 200 for anything that
reached the controller.

**Stock looks lower than the shelf.** Reservations from unpaid orders are not
being released — the scheduler is not running. Check the crontab, then
`ReleaseStaleReservations` in the queue log.
