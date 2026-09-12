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

**The executable version of §2–§6 is `deploy/provision.sh`** — idempotent,
run as root with the domain as its argument:

```bash
bash deploy/provision.sh portal.example.co.id
```

It stops before anything it cannot decide for you (SSH hardening, the DB
password, `.env`) and prints what is next. The sections below remain the
explanation of *why* each step is what it is; the script is the *how*, and
when they disagree, the disagreement is the bug.

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

# PERUSAHAAN_* and PAJAK_PENJUAL_* need no .env entries any more: the Owner
# types them into Pengaturan → Pengaturan perusahaan, audited, and the
# faktur/portal/launch checklist read them the same second. The env keys
# remain as fallbacks only.

# Faktur pajak. The format and Coretax's four reference codes have no screen —
# they are release-level decisions checked once with the accountant. §7b.
PAJAK_FORMAT_EKSPOR=coretax_xml            # the Coretax bulk-import XML
PAJAK_NEGARA_PEMBELI=IDN                   # ISO 3166-1 alpha-3. IND is India
PAJAK_KODE_BARANG=000000                   # goods with no specific classification
PAJAK_SATUAN_PCS=UM.0001                   # Coretax unit code for our PCS
PAJAK_SATUAN_SET=UM.0001                   # …and for SET

BACKUP_ENCRYPTION_KEY=…                    # php artisan backup:key
BACKUP_DISK=…                              # NOT this machine — see docs/BACKUP.md
SESSION_SECURE_COOKIE=true                 # the cookie never travels plain http
LOG_CHANNEL=daily                          # rotates itself; 14 days kept
```

`APP_DEBUG=false` is not a style preference. A stack trace on an exception page
carries the database credentials out to whoever triggered it.

`APP_URL` must be the real https address. It is what invoice PDFs and portal
links build from.

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

The file lives in the repo as **`deploy/Caddyfile`**; `provision.sh` installs
it with the real domain substituted. Two decisions in it worth knowing:

- `request_body max_size 32MB` — the panel and the importer both move real
  files (the supplier workbook, the faktur pajak export), and the default cap
  rejects them.
- **HSTS is the only header Caddy sets.** nosniff, frame-options and
  referrer-policy come from the application's own SecurityHeaders middleware,
  where the test suite asserts them per surface. An earlier revision set them
  in both places with different values — two `X-Frame-Options` headers on
  every response, and which one a browser honours is not a bet worth making.
  The TLS terminator owns exactly the one header the app cannot honestly send
  for itself.

Point the domain's A record at the VPS first — Caddy obtains the certificate on
first request and cannot do so until DNS resolves. Then
`systemctl reload caddy`.

---

## 6. Queue workers and the scheduler

Both are required for correctness, not convenience — `REQUIREMENTS.md §6` has
the table of what breaks without them. The short version: without the queue, no
payment is ever posted; without the scheduler, stock stays fenced by orders
nobody paid for, and a payment whose worker died is never recovered.

The unit file is **`deploy/supervisor/webtransaction-worker.conf`** in the
repo; `provision.sh` installs it. Two settings in it carry the reasoning:
`numprocs=2` (one worker can be busy with a slow price-list import while the
other still picks up the next job — twenty orders a day needs no pool), and
`stopwaitsecs=3600`, which lets a worker finish the job in its hands before
supervisor kills it.

```bash
supervisorctl reread && supervisorctl update && supervisorctl start webtransaction-worker:*
```

The scheduler, as `deploy`'s crontab:

```cron
* * * * * cd /var/www/webtransaction && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. The bank account on the faktur

There is no payment gateway. Customers pay by transfer to the company account,
by cash, or by giro through their sales; finance records each one against the
bank statement, and a covered invoice advances its own order. The deploy-side
work is exactly one thing: the account the faktur tells customers to pay into.

The Owner enters it on **Pengaturan → Pengaturan perusahaan** — no SSH, no
`.env`, and the change lands in the audit log with old and new values.

Then open any unpaid faktur and read the payment block at the bottom. The
number printed there is where customer money will go — verify it against the
bank book, not against the `.env` you just typed. The launch checklist fails
on the shipped placeholder, but it cannot tell a typo from a real account;
only a person reading the printed faktur can.

The transfer instruction asks the customer to quote the faktur number in the
berita. That reference is what lets finance match a statement line to an
invoice in the **Pembayaran belum cocok** queue instead of ringing the
customer to ask what the money was for.

---

## 7b. The faktur pajak export (Coretax XML)

There is no Coretax API. Finance makes a file on **Buku besar → Faktur pajak**,
uploads it to Coretax by hand, and pastes the returned serials back in. The
file is the Coretax bulk-import XML, written to the template the accountant
provided. Deploy owns two things about it: **who the seller is**, and **the
layout plus four reference codes that belong to Coretax rather than to us**.

### The seller, on a screen

`Pengaturan → Pengaturan perusahaan → Identitas penjual` — NPWP, nama wajib
pajak, and **ID TKU penjual (NITKU)**. Owner only, audited, no SSH. The export
refuses to write a file at all while the NPWP is empty, with that sentence on
screen rather than a stack trace, because a faktur with no seller on it is not
a file anybody should be uploading.

ID TKU is 22 digits: the 16-digit NPWP plus a six-digit place-of-business code.
**Leave it empty unless the fakturs are issued from a registered branch** — the
system then derives the head office form, NPWP + `000000`, which is right for
almost everybody. A 15-digit NPWP gains its leading zero automatically; that is
the published conversion, not a guess.

The same field exists per customer, on the customer form, for a buyer who
purchases through their own registered branch. Empty means head office there
too.

### The layout and the four codes, in `.env`

These have no screen on purpose: each one changes every future filing, and the
right moment to set them is once, with the accountant, at deploy time.

| Key | Ships as | What it is |
|---|---|---|
| `PAJAK_FORMAT_EKSPOR` | `coretax_xml` | The layout. `efaktur_csv` is the old desktop e-Faktur CSV and exists only so a filing made under it stays reproducible |
| `PAJAK_NEGARA_PEMBELI` | `IDN` | Buyer country, ISO 3166-1 alpha-3. **The sample template read `IND`, which in that standard is India** — hence the default here, and hence this row |
| `PAJAK_KODE_BARANG` | `000000` | The goods code Coretax accepts for goods with no specific classification |
| `PAJAK_SATUAN_PCS` | `UM.0001` | Coretax's unit code for a line counted in PCS |
| `PAJAK_SATUAN_SET` | `UM.0001` | …and for SET. The template carried `UM.0001` for a line of goods; whether SET has its own code is the accountant's answer, not ours |

**The filing screen prints all four**, under the format banner, in the words
the accountant will recognise. That is the check: open
`/admin/akuntansi/faktur-pajak` as Finance and read the line beginning *Kode
rujukan yang dipakai*. If it disagrees with the accountant, change `.env` and
`php artisan optimize` — nothing else moves, because nothing else reads them.

### What is deliberately not configurable

The rate, the DPP factor and the transaction code (`PPN_*`) are law, not
preference, and changing them changes what a customer was billed. Per-line DPP
and PPN are snapshots taken when the price was locked: the writer copies them
and never recomputes, so a filed month prints the same figures forever whatever
the rate does afterwards. `CLAUDE.md` says to confirm with the accountant
before touching any of it, and that still holds.

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

**Most of this list is on a screen.** `/admin/kesiapan-peluncuran`, Owner only,
checks nine of these items against the live system every time it is opened —
the config values, the price list, the seeded passwords, the backup, the
control accounts — and there is no way to tick those by hand. The rest are
recorded there as a statement with a name and a date against them.

The list stays here because the server steps below have no screen, and because
a runbook somebody can read before touching the machine is worth having.

Run the whole list from the terminal you are already in:

```bash
php artisan launch:check
```

Same checks as the screen, one exit code — non-zero while anything blocks, so
a deploy script can gate on it. Attestations are still signed on the screen,
by the Owner, with evidence.

Everything below is a launch blocker, and only the first two are code.

- [ ] `php artisan launch:check` exits 0
- [ ] The payment block on a printed faktur shows the real company account
- [ ] `php artisan backup:restore --into=scratch` — actually restored, not just written
- [ ] Real company details replacing the placeholders in `config/perusahaan.php`
      — the profile text, and above all the **partners**, which are invented
- [ ] `PERUSAHAAN_NPWP`, `PERUSAHAAN_NIB`, `PAJAK_PENJUAL_NPWP` and
      `PAJAK_PENJUAL_NAMA` set in `.env` (or from Pengaturan perusahaan). Every
      one is empty by default, and the faktur pajak export needs the last two
      before a single filing. `PAJAK_PENJUAL_ID_TKU` only if the fakturs are
      issued from a registered branch rather than the head office
- [ ] A real price list imported and published — `php artisan migrate --seed`
      deliberately ships no prices, because a seeded price is a price nobody
      approved
- [ ] PSE Lingkup Privat registration via OSS → PB-UMKU
- [ ] A lawyer's reading of the two legal pages — they are drafted, not reviewed
- [ ] The two commercial values marked `>>> PUTUSKAN` in `config/legal.php`:
      the late-payment rate (default 2%/month) and the claim window (default 3
      days). Both are defaults nobody has agreed to yet
- [ ] The four Coretax reference codes read back to the accountant from the
      Faktur pajak screen and confirmed — buyer country, goods code, and the
      unit code for PCS and for SET. **§7b** has the table and what each
      default rests on; none of them can be verified from inside this
      repository, which is why they are on a screen and on this list
- [ ] Staff passwords changed from the seeded `password`
- [ ] One real order taken end to end by staff, on the real system, before any
      buyer has a login — that is what build order phase 1 is for

---

## 10. Routine deploys after that

```bash
cd /var/www/webtransaction && bash deploy/deploy.sh
```

Which is exactly:

```bash
df -Pm .                      # refuses to start under 1 GB free
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes-*.php
php artisan optimize && php artisan filament:optimize
php artisan queue:restart
php artisan up
```

Two of those lines exist because of one failure mode. `artisan optimize`
writes `bootstrap/cache/config.php` in a single pass; killed halfway — out of
disk, out of memory — it leaves a truncated PHP file, and every later artisan
command dies with `Target class [config] does not exist`, **including the ones
that would clear it**. So the script checks for room before it starts, and
deletes the cache files with `rm` (which needs no working application) before
rebuilding them. It also checks that the build actually produced
`public/build/manifest.json` before going any further — a missing manifest
breaks nothing until a page is rendered, by which time maintenance mode is
already lifted and every request is a 500.

On failure the script asks whether the code on disk can serve — it boots, and
the assets exist. If it can, the maintenance page comes down; if it cannot,
the 503 deliberately **stays up** and the recovery is printed. A page saying
come back shortly is honest; a stack trace on every URL is not.

— followed by an informational `launch:check`. The first deploy uses
`bash deploy/deploy.sh --first`, which builds, writes a fresh `.env`, and
stops for you to fill it rather than migrating against an empty password.

## 10a. Upgrading a box that is already live (2026-09 release)

A routine `deploy/deploy.sh` carries all of this except the `.env` lines —
those are the only hand work, and nothing breaks while they are missing:
the defaults in `config/pajak.php` are the same values, so the export works
before you touch `.env` and the file is honest about which codes it used.

```bash
cd /var/www/webtransaction
bash deploy/deploy.sh                 # pull, build, migrate, storage:link, restart
```

What that migration adds is one nullable column, `companies.id_tku` — the
buyer's place-of-business code for the XML. Additive, like every other, so
there is nothing to plan around.

Then, once, as `deploy`:

```bash
cd /var/www/webtransaction
cat >> .env <<'ENV'

# Faktur pajak — Coretax XML (docs/DEPLOY.md §7b)
PAJAK_FORMAT_EKSPOR=coretax_xml
PAJAK_NEGARA_PEMBELI=IDN
PAJAK_KODE_BARANG=000000
PAJAK_SATUAN_PCS=UM.0001
PAJAK_SATUAN_SET=UM.0001
ENV

php artisan optimize                  # .env is only read when the cache is built
```

`php artisan optimize` is not optional here. Production caches the config, so
an edited `.env` changes nothing at all until it is rebuilt — which looks
exactly like the setting being ignored.

Appending is safe on a box that has never carried these keys, which is every
box before this release. Running it a second time is not: **if a key is
already in the file, edit that line** rather than leaving two copies of it.

Three things to check afterwards, in this order:

1. **`/admin/akuntansi/faktur-pajak`, as Finance.** The banner says *File XML
   impor Coretax*, and the line under it prints the four codes. If it still
   says *Pastikan dulu formatnya*, the config cache did not get rebuilt.
2. **`Pengaturan → Pengaturan perusahaan → Identitas penjual`, as the Owner.**
   NPWP and nama filled; ID TKU only for a branch. Export refuses without the
   NPWP.
3. **Export one past month and open the file.** `<TIN>` is the company, 16
   digits; `<RefDesc>` is our own faktur number on each `TaxInvoice`. Upload a
   single-faktur month to Coretax first, not the backlog — a rejected batch
   tells you less than a rejected one.

Two more changes in this release need no deploy work, and are here so nobody
goes looking for a setting: **Impor pelanggan** now takes the accounting
package's own customer workbook (.xlsx or the same columns as CSV), and
**Komisi & target** now opens for Finance as well as the Owner.

---

## 10b. The pipeline: push to `production`, and the rest is mechanical

Promotion is a branch, not a button. Work lands on the working branch; when a
release is ready, merge (or push) it to **`production`** and
`.github/workflows/deploy.yml` takes over: the full test suite runs first —
the same `tests.yml` the working branch runs, called rather than copied, so
the gate cannot drift — and only a green suite reaches the deploy job, which
SSHes to the VPS and runs `deploy/deploy.sh`. One deploy at a time, never
cancelled mid-flight: a killed deploy leaves the site in maintenance mode.

Set once, in the repository's Actions secrets:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | The VPS address |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_SSH_KEY` | A private key made **for this pipeline** — generate a fresh pair, put the public half in `/home/deploy/.ssh/authorized_keys`, and never reuse a person's key |
| `DEPLOY_KNOWN_HOSTS` | `ssh-keyscan -H <host>` output — pinned, so the pipeline refuses to talk to anything that is not the VPS |

Then two one-time settings:

- **Repo → Settings → Environments → `production` → required reviewers.**
  That puts a named human approval between green tests and the live server —
  the same two-keys shape the application itself runs on.
- **On the VPS, the clone tracks `production`:**
  `git -C /var/www/webtransaction checkout production` after the first
  deploy, so the pipeline's `git pull --ff-only` fast-forwards to exactly
  what was tested and nothing else.

`workflow_dispatch` on the Deploy workflow re-deploys the branch as it
stands — for the day a deploy dies halfway and the fix is "run it again".

`queue:restart` is not optional. Workers are long-lived processes holding the
old code in memory; without it, a deploy that changes a job leaves the previous
version running until something else restarts it.

Migrations are additive by project rule, so `migrate --force` on a live database
is safe — nothing shipped is ever edited. That rule is what makes this list this
short, and it is worth keeping.

---

## Watching it run

The box examines itself. `App\Domain\Ops\OpsHealth` runs seven checks —
PostgreSQL, Redis, queue backlog, failed jobs, a scheduler heartbeat, backup
age, disk space — and three things read them:

- **`php artisan ops:check`** — the terminal view. Exit code 0 healthy,
  1 degraded, 2 broken, so a cron line or an external prober can page on
  the number without parsing Indonesian.
- **The Owner's dashboard** shows a Kesehatan sistem banner **only when a
  check is not green** — same rule as the backup banner: a permanent tick
  stops being read within a week.
- **An email to the Owner** when anything goes GAWAT — once per incident,
  not hourly; the throttle clears on recovery so the next incident mails
  immediately. The mail is deliberately not queued: an alert about a dead
  worker that waits for a worker is a punchline.

### Are the numbers on it true?

A different question from "is the box alive", and it has its own command.
`php artisan integritas:periksa` asks whether every cached column still agrees
with the ledger behind it — stock against its kartu stok, reservations against
the reservations actually held, inventory value against the cost ledger, every
control account against its subledger. **Exit 0 clean, 1 drifted.**

Worth running at three moments: after a restore drill (it is the cheapest
proof the data survived), after any deploy that touched the ledgers, and
before closing a month. A nightly job (`SweepLedgerIntegrity`, 01:30) asks the
same question unprompted and tells the Owner through the panel's bell; the
Owner's dashboard shows a panel **only when something has drifted**.

It never repairs anything, deliberately. Rebuilding a cache from its ledger
makes the symptom vanish and leaves whatever wrote outside the domain classes
to do it again next week, unwitnessed. If it reports a drift, the question to
answer is *what wrote that column*, and the answer is usually a hand-run SQL
statement or a code path that skipped the domain class.

Two findings only a human can arrange:

- **External uptime.** This box cannot see itself vanish from the internet.
  Point a free prober (UptimeRobot or similar) at `https://<domain>/up`
  every 5 minutes — that endpoint is intentionally bare and safe to expose.
  Everything richer stays in `ops:check`, which never leaves the server.
- **Where the alert email lands.** It goes to the Owner accounts' addresses;
  make sure at least one is a mailbox somebody reads on a Saturday.

Slow queries (over one second) are logged as warnings in production —
on twenty orders a day a slow query is a missing index, not load. The
worker and Caddy logs rotate weekly via `deploy/logrotate/webtransaction`;
the Laravel log rotates itself on the `daily` channel.

## When something is wrong

The three failures that actually happen, and what each looks like:

**Staff cannot log in, panel returns 500.** Redis is down. It is on the login
path — Filament's rate limiter uses the cache. `systemctl status redis-server`.

**Orders reach `awaiting_payment` and never move, but the money is in the
bank.** Nobody has recorded the payment — settlement is finance's hand, not a
callback. Check the **Pembayaran belum cocok** queue and the bank statement;
recording the entry against the invoice moves the order the same second.

**Stock looks lower than the shelf.** Reservations from unpaid orders are not
being released — the scheduler is not running. Check the crontab, then
`ReleaseStaleReservations` in the queue log.

**Every page 500s with `Unable to locate file in Vite manifest`.** The
front-end assets were never built — `npm run build` died, usually out of
memory on a small box. Nothing is wrong with the database:

```bash
cd /var/www/webtransaction
free -m                      # if tight, add swap before retrying
npm ci && npm run build
# still killed? give node a ceiling it can meet:
NODE_OPTIONS=--max-old-space-size=1024 npm run build
ls -l public/build/manifest.json    # must exist and be non-empty
php artisan optimize && php artisan up
```

**A stack trace is visible on a public URL.** `APP_ENV` is not `production`,
so the debug fail-safe in `AppServiceProvider` never fires — and neither does
any other production hardening: secure cookies, HTTPS enforcement, the strict
headers. Fix both lines in `.env` and rebuild the cache:

```bash
APP_ENV=production
APP_DEBUG=false
```
```bash
php artisan optimize
```

**Every `php artisan` command dies with `Target class [config] does not
exist`.** The cached config is truncated — a deploy ran out of disk or memory
mid-write. Nothing is corrupted in the database and no data is at risk; the
file just has to go:

```bash
cd /var/www/webtransaction
rm -f bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes-*.php
php artisan optimize
php artisan up          # the failed deploy left the site in maintenance mode
df -h /                 # and find out why it ran out
```
