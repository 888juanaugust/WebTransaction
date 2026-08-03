# Requirements

Everything needed to run WebTransaction, from a bare server upward.

`composer.json` and `package.json` are the machine-readable manifests and the
lock files are authoritative for exact versions. This document exists for the
things a lock file cannot say: which system services must exist, which PHP
extensions the code actually reaches for, and *why* each dependency is here —
so that a year from now somebody can tell what is safe to remove.

Versions below are what the project is verified against, as of the current lock
files.

---

## 1. System

| Component | Version | Notes |
|---|---|---|
| PHP | 8.4 (min 8.3) | `composer.json` allows `^8.3`; CI tests 8.3 and 8.4. |
| PostgreSQL | 16 | Not optional — see below. |
| Redis | 7 | Queue and cache. |
| Node.js | 22 LTS | Build-time only. Not needed at runtime on the server. |
| Composer | 2.8+ | |
| Caddy | 2.x | TLS termination. Automatic certificates. |
| Supervisor | 4.x | Keeps the queue workers alive. |

### PostgreSQL is a hard requirement, not a preference

The schema uses `SELECT ... FOR UPDATE` row locks (stock reservation), partial
unique indexes (`stock_reservations_one_held_per_line`), and `jsonb` columns.
SQLite and MySQL will not carry this schema, and the test suite is configured
against Postgres for the same reason. Do not "simplify" this to SQLite for
local development — the concurrency behaviour under test is the point.

### Redis is on the login path

`CACHE_STORE=redis`, and Filament's login rate limiter uses the cache. If Redis
is down, staff cannot log in — the panel returns 500. Supervisor should be
watching it, and it belongs in whatever uptime check you run.

---

## 2. PHP extensions

Required by the framework and by the dependencies below. Most ship with a
standard `php8.4-*` distribution package.

| Extension | Needed for |
|---|---|
| `pdo_pgsql`, `pgsql` | PostgreSQL. Nothing works without these. |
| `redis` (phpredis) | Queue and cache driver. `REDIS_CLIENT=phpredis`. |
| `mbstring` | Framework-wide. |
| `openssl` | `APP_KEY` encryption, TLS to the Xendit API. |
| `gd` | phpspreadsheet — reading the supplier workbooks. |
| `zip` | phpspreadsheet — `.xlsx` is a zip archive. |
| `dom`, `libxml`, `simplexml`, `xml`, `xmlreader`, `xmlwriter` | phpspreadsheet parses the OOXML inside. |
| `iconv`, `ctype`, `fileinfo`, `filter`, `hash`, `json`, `pcre`, `session`, `tokenizer`, `zlib` | Framework and standard library. |
| `intl` | Locale-aware formatting for the Indonesian UI. |
| `pcntl`, `posix` | Graceful queue worker shutdown. Server only. |

Debian/Ubuntu:

```bash
apt install php8.4-{cli,fpm,pgsql,redis,mbstring,gd,zip,xml,intl,curl,bcmath}
```

`bcmath` is not currently required — all money arithmetic in `App\Domain\Money`
is integer-only, deliberately. It is listed because it is cheap insurance if
anyone later reaches for arbitrary precision.

---

## 3. PHP packages (Composer)

### Runtime

| Package | Version | Why it is here |
|---|---|---|
| `laravel/framework` | `^13.8` (v13.23.0) | The framework. |
| `filament/filament` | `^4.0` (v4.12.5) | Admin panel: worklist widgets, resources, forms, tables. Pulls in Livewire. |
| `phpoffice/phpspreadsheet` | `^5.9` (5.9.0) | Reads the supplier price list workbooks (`.xlsx`) and our own canonical export. The tolerant importer is built on it. |
| `laravel/tinker` | `^3.0` (v3.0.2) | REPL. Used for operational one-offs; not on any request path. |

Transitive but worth knowing about, because the admin panel is built on them:
`livewire/livewire`, `filament/{forms,tables,schemas,actions,widgets,support,notifications}`.

### Development

| Package | Version | Why |
|---|---|---|
| `phpunit/phpunit` | `^12.5.12` (12.5.33) | Test runner. |
| `laravel/pint` | `^1.27` (v1.30.3) | Formatter. CI fails on unformatted code (`pint --test`). |
| `fakerphp/faker` | `^1.23` (v1.24.1) | Model factories. |
| `mockery/mockery` | `^1.6` (1.6.12) | Test doubles. |
| `nunomaduro/collision` | `^8.6` (v8.9.5) | Readable CLI failures. |
| `laravel/pail` | `^1.2.5` (v1.2.7) | Tail application logs in development. |
| `laravel/pao` | `^1.0.6` (v1.1.3) | Laravel dev tooling. |

**None of the dev packages may be installed in production.** Deploy with
`composer install --no-dev --optimize-autoloader`.

---

## 4. JavaScript packages (npm)

All dev dependencies — the output is static CSS/JS committed to `public/build`
at deploy time. There is no Node runtime on the server.

| Package | Version | Why |
|---|---|---|
| `vite` | `^8.0.0` | Asset bundler. |
| `laravel-vite-plugin` | `^3.1` | Manifest and hot reload integration. |
| `tailwindcss` | `^4.3.3` | Utility CSS. |
| `@tailwindcss/vite` | `^4.3.3` | Tailwind v4 Vite plugin. |
| `concurrently` | `^9.0.1` | Runs server + queue + logs + vite together in `composer dev`. |

Two things about the asset build:

- **No remote fonts.** The generated Vite config fetched a webfont from
  bunny.net at build time. That was removed: the build must work on the VPS and
  in CI without reaching a third-party CDN, and it failed behind an outbound
  proxy.
- **Filament's own assets are not committed.** `public/{css,js,fonts}/filament`
  is gitignored and regenerated by `php artisan filament:assets` on deploy.
  Committing them turns every Filament upgrade into a 4 MB binary diff.

---

## 5. External services

| Service | Purpose | Configuration |
|---|---|---|
| Xendit | Fixed Virtual Account payments | `XENDIT_SECRET_KEY`, `XENDIT_CALLBACK_TOKEN` |

Xendit is the only third-party runtime dependency, and only for money in.

`XENDIT_CALLBACK_TOKEN` must be set in every environment that is reachable from
the internet. When it is empty the webhook controller **refuses all callbacks**
rather than accepting unauthenticated ones — an unconfigured environment must
not be able to mark orders paid.

Coretax is deliberately *not* an integration. Faktur output is a CSV export
matching the Coretax import format; there is no API dependency.

---

## 6. Background processes

Both are required for correctness, not just convenience.

```
php artisan queue:work --queue=default --tries=5
php artisan schedule:work        # or a system cron running schedule:run each minute
```

The scheduler runs two jobs that protect data integrity:

| Job | Interval | Consequence if it never runs |
|---|---|---|
| `ReleaseStaleReservations` | 15 min | Stock stays fenced by orders that were never paid, and the warehouse looks emptier than it is. |
| `SweepStuckWebhookEvents` | 5 min | A payment whose worker died mid-transaction is never posted. Nothing else recovers it — the gateway already received its 200 and will not redeliver. |

Supervisor should restart workers on exit. Worker restarts are safe at any
moment: the callback job commits its payment entry and its done-marker in one
transaction, so a kill mid-flight rolls back cleanly and the sweep picks it up.

---

## 7. Environment variables

`.env.example` is the complete list. The ones with no safe default:

| Variable | Notes |
|---|---|
| `APP_KEY` | `php artisan key:generate`. Losing it makes encrypted data unreadable. |
| `DB_*` | PostgreSQL connection. |
| `XENDIT_SECRET_KEY`, `XENDIT_CALLBACK_TOKEN` | Sandbox values in development. |
| `PPN_RATE_BPS`, `PPN_DPP_NUMERATOR`, `PPN_DPP_DENOMINATOR`, `PPN_TRANSACTION_CODE` | Tax under PMK 131/2024. **Confirm with the accountant before changing.** |
| `RESERVATION_TTL_MINUTES` | How long an unpaid confirmed order holds stock. Default 2880 (48h). |

---

## 8. Local setup

```bash
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

createdb webtransaction
createdb webtransaction_test          # the suite runs against a real database
php artisan migrate --seed

php artisan serve
php artisan queue:work
```

Requires PostgreSQL and Redis running locally.

### Verifying the install

```bash
php artisan test          # 99 tests
./vendor/bin/pint --test  # formatting
```

Both are what CI runs. If the suite errors with `SQLSTATE[08006] ... Connection
refused`, Postgres is not up; if login returns 500, Redis is not up.

---

## 9. Production deploy checklist

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan filament:assets
php artisan migrate --force
php artisan optimize          # config + route + view caches in one step
php artisan filament:optimize # caches Filament components and Blade icons
php artisan queue:restart     # workers must reload the new code
```

Plus, before going live: PSE Lingkup Privat registration, a Kebijakan Privasi
page (UU PDP 27/2022), and a nightly encrypted off-box `pg_dump` whose restore
you have actually tested.
