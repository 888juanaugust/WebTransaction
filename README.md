# WebTransaction

B2B wholesale portal for automotive spare parts, Indonesian market.

Buyers are bengkel, toko sparepart and distributors — not retail consumers. Three surfaces sit
over one shared domain core: a public site (open, no prices), a buyer portal (authenticated,
per-customer prices), and an admin panel (staff, role-scoped).

This repository currently contains **build phase 1**: the domain core and the admin panel. No
buyer login exists yet, by design — phase 1 has to run the real business before anyone outside
the company gets an account.

## Stack

Laravel 13 · Livewire · Filament 4 · PostgreSQL 16 · Redis (queue + cache) · Xendit fixed VA.
Single VPS in Jakarta, Caddy for TLS.

Full dependency list, PHP extensions and deploy steps: **[REQUIREMENTS.md](REQUIREMENTS.md)**.

## Getting started

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate

createdb webtransaction && createdb webtransaction_test
php artisan migrate --seed          # staff accounts, warehouse, price tiers

php artisan serve
php artisan queue:work              # supervisor-managed in production
php artisan schedule:work           # releases stale reservations, recovers stuck callbacks
```

Needs PostgreSQL and Redis running. Redis is on the login path — the panel
returns 500 without it.

The seeder creates one account per role at `<role>@example.test` / `password`
(`sales`, `warehouse`, `finance`, `owner`). The admin panel is at `/admin`.

Prices and products are deliberately **not** seeded. They come from a real price list import,
because a seeded price is a price nobody approved.

### Tests

```bash
php artisan test
```

Tests run against PostgreSQL, not SQLite — the schema depends on `FOR UPDATE` row locks and
partial unique indexes.

## Where things live

| Path | What |
|---|---|
| `app/Domain/Pricing/PriceResolver.php` | The one pricing function. Everything calls it. |
| `app/Domain/Tax/TaxCalculator.php` | PPN under PMK 131/2024. |
| `app/Domain/Stock/StockLedger.php` | Append-only stock ledger, reservations. |
| `app/Domain/Credit/CreditChecker.php` | Credit exposure and limit checks. |
| `app/Domain/Orders/OrderStateMachine.php` | Every order transition. |
| `app/Domain/Payments/PaymentLedger.php` | Append-only money ledger. |
| `app/Domain/Billing/InvoiceIssuer.php` | Confirmed order → invoice, from line snapshots only. |
| `app/Domain/Documents/` | Gapless per-period document numbering. |
| `app/Domain/PriceList/` | Tolerant importer, diff, versioned publishing. |
| `app/Filament/Widgets/` | The admin worklist queues. |
| `app/Filament/Portal/Widgets/` | The buyer portal landing screen. |
| `app/Support/BrandColors.php` | The company palette. |
| `config/perusahaan.php` | All public-site content — profile, partners, contact, roadmap. |

## The three surfaces

| URL | Who | Auth |
|---|---|---|
| `/` | Public — company profile, partners, contact, roadmap | none |
| `/admin` | Staff — orders, stock, billing, price lists | `web` guard, `users` |
| `/portal` | Buyers — credit, invoices, order history | `customer` guard, `customer_users` |

Staff and buyers authenticate on **different guards against different tables**,
so a buyer session carries no staff identity at all — the isolation is
structural rather than a permission check somebody can forget to write.
`/masuk` is the public chooser between the two.

Buyer logins are created by staff on a customer's record in the admin panel.
There is no self-registration: a wholesale account exists only after the
business is verified and a credit limit agreed.

### Inside the buyer portal

Built in the order `CLAUDE.md` puts them in, which is the order a restocking
buyer needs them:

| Screen | What |
|---|---|
| Dashboard | Available credit, recent orders each with **Pesan ulang**, open invoices |
| `/portal/pesanan` | Order history, line detail from the price snapshots |
| `/portal/katalog` | The catalogue at **this buyer's** resolved prices, with add-to-cart |
| `/portal/keranjang` | The basket: quantities, indicative totals, checkout |
| `/portal/tagihan` | Invoices, due dates, and the fixed VA to pay into |

**Reorder is the feature.** A B2B buyer restocks the same 15–20 SKUs forever,
so the modal opens with last time's quantities filled in and every line
editable; 0 drops a line. It creates a draft and **submits** it — staff still
confirm, because confirmation is where credit is checked and stock is reserved,
and neither is a customer's call. A portal-placed order records the buyer in
`orders.placed_by_customer_user_id` and `order_events.customer_actor_id` rather
than inventing a staff `created_by`.

Two structural guards, both with tests that fail if they are removed:

- **`ScopedToBuyer`** scopes a resource's *base* query to the signed-in buyer's
  company, so the list, the record route and global search are all covered at
  once. `PortalScopingTest` reads the schema and fails if any portal resource
  over a table with a `company_id` does not use it — the requirement is derived,
  not remembered. It throws rather than returning null when no buyer is in
  session, because a null would quietly become `where company_id is null`.
- **`ReadOnlyInPortal`** means no portal resource has generic create or edit.
  The one thing a buyer creates is an order, and that goes through the state
  machine.

The catalogue is deliberately *not* scoped — products are not customer data.
Only the prices differ, and those come from `resolvePrice()` like everywhere
else.

#### The cart

**There is not one money column in `carts` or `cart_items`, and there must
never be one.** A rupiah figure stored in a basket is a second source of truth
for a number `resolvePrice()` owns and the order line snapshots at `confirmed`
— stale the moment a price list is published, and exactly the figure a customer
would quote back at you. A cart holds what was asked for; what it costs is
answered live by `CartTotals` and stored nowhere. `CartTest` reads the schema
and fails if a money column appears.

`qty_base` is absent for the same reason: it is derived from the product at
checkout, so a carton size changed while an item sat in a basket is picked up
rather than baked in.

Two more things worth knowing:

- **Checkout cannot double-submit.** The cart row is locked and emptied in the
  same transaction that creates the order, so a double-clicked button or a
  second tab finds an empty basket and is told so, rather than putting two
  identical orders on a customer's account.
- **Short stock and a tight credit limit are warnings, not gates.** Both are
  decided at `confirmed` under a row lock; a decision made on the cart screen
  would be stale by the time staff looked at it, and refusing an order the
  warehouse could actually fill is worse than a warning.

One basket per login rather than per company — two people at the same bengkel
editing one set of quantities has no sensible resolution.

Public-site content lives in `config/perusahaan.php` — **the shipped text is
placeholder and must be replaced before launch**, especially the joint-venture
partners, since naming a company in public is a claim about a real business
relationship.

### Language

| Surface | Language |
|---|---|
| Home page (`/`) | English |
| Every other public page | Bahasa Indonesia |
| Admin panel and buyer portal | Bahasa Indonesia |

The app locale stays `id` throughout. Switching it per route would also flip
Filament, date formatting and validation messages, so instead prose that
appears in both languages is stored as `['id' => …, 'en' => …]` in
`config/perusahaan.php` and read through `App\Support\Perusahaan`, which sets
`<html lang>` per page and falls back to Indonesian when a translation is
missing.

Values that read the same either way — company name, brand names, phone
numbers, category names like `HYDRAULIC PART` — stay plain strings and are not
duplicated.

Note the home page's nav is English but every link on it leads to an
Indonesian page. That follows from "home page in English, the rest in Bahasa";
say so if you'd rather the nav labels stayed Indonesian throughout.

## Look and feel

Clean white surfaces, company blue `#1D4ED8`, company red `#DC2626`.

Red is not decorative anywhere in the panel: it means stock is short, an
invoice is overdue, or the action destroys something. Rows carrying a red
*value* get a red left edge so a manager cannot scroll past them. That only
keeps working if red stays scarce — please don't spend it on ordinary
buttons.

Green survives in exactly one role: settled money (`Lunas`, `Selesai`). Every
other forward action is blue.

The palette lives in `app/Support/BrandColors.php`; the surface layer is
`resources/css/filament/admin/theme.css`. After changing either, run
`npm run build`.

**Dark mode** applies to the two panels and keys off Filament's `.dark` class
on `<html>` — *not* `prefers-color-scheme`. Filament stops following the OS the
moment a user picks a theme, so anything written against the media query paints
light styles over dark chrome for anyone whose toggle disagrees with their
laptop. Light rules are scoped `:where(html:not(.dark))`, dark rules `html.dark`,
and `ThemeTest` fails the build if that slips.

The public site is deliberately light-only and declares `color-scheme: light`,
so a visitor on a dark-mode OS doesn't get dark browser chrome — scrollbars,
selects, autofill — drawn over a white page.

## The order lifecycle

```
draft  →  submitted  →  confirmed  →  awaiting_payment  →  paid  →  shipped  →  completed
  ↑            ↑             ↑                ↑              ↑         ↑
order       "Ajukan"     "Setujui"       "Tagihkan"      Xendit    "Tandai
 form                    prices lock,     invoice +      webhook    dikirim"
                         stock held      VA issued                 stock out
```

Three of those steps are where the money is decided, and each does its work in
a single transaction:

- **confirmed** — every line snapshots its price, discount, DPP, PPN and price
  list version; credit is checked; stock is reserved under a row lock.
- **awaiting_payment** — the invoice is issued from those snapshots and the
  buyer is given a fixed Virtual Account to pay into.
- **paid** — reachable *only* from the gateway webhook.

Without a Xendit key, virtual accounts are minted locally so the whole chain
runs on a laptop. A flow you cannot complete locally is one people end up
testing on production data.

## The invariants this code is built around

These are load-bearing. `CLAUDE.md` is the full statement of them; the short version:

1. **Stock and money are append-only ledgers.** No `UPDATE products SET stock = stock - n`.
   `stock_levels` is a cache, and `StockLedger::reconcile()` proves it can be rebuilt by
   summing `stock_movements`.
2. **Pricing is one pure function.** `resolvePrice(company, sku, qty, date)` returns a price and
   the reason it resolved that way. No second implementation anywhere. A caller pricing a batch
   calls `prime()` first so the cost stops scaling with the number of SKUs — that is a cache
   hint, not a second code path, and `resolve()` returns the same price with or without it.
3. **Order lines snapshot their price** at `confirmed`. Historical orders and invoices never
   join to the live price list.
4. **`paid` is set only by the gateway webhook.** Never a browser redirect, never a controller
   responding to a user action. Idempotency comes from a UNIQUE constraint on the gateway
   event id.
5. **Unit of measure is modeled.** Order lines store both the ordered unit/quantity and the
   resolved base quantity; the ledger is always in base units.
6. **Money is BIGINT rupiah.** Never float.
7. **Stock is reserved at `confirmed`, decremented at `shipped`**, under a row lock. A
   scheduled job releases reservations on stale unpaid orders.

Tests exist for the five places where bugs cost money: price resolution, credit check, stock
reservation, webhook handling, tax calculation.

Two of those are tested in ways worth knowing about:

- **Stock reservation** is raced for real. `StockReservationConcurrencyTest` forks a process per
  order and starts them together on a Postgres advisory lock, because a single-threaded test
  cannot tell a working `FOR UPDATE` from no lock at all. Strip the lock and it fails; that was
  checked, not assumed.
- **Pricing cost** is asserted, not just pricing correctness. `PricingQueryCostTest` fails if
  anyone reintroduces a per-SKU query — the kind of change that leaves every other test green
  while confirming an order runs eighty queries inside the stock-lock transaction.

## Tax

Headline PPN is 12%, but for ordinary non-luxury goods the DPP is `11/12 × harga jual`
(PMK 131/2024), so the effective burden stays at 11%. In Coretax that is transaction code
**04**, not 01. DPP and PPN are computed and stored **per line item**, never only on the order
total — summing rounded lines is not the same number as rounding a summed total, and the faktur
has to agree with the lines printed on it.

Faktur output is a CSV export matching the Coretax import format. There is no API integration.

**Confirm any change to tax logic with the accountant first.**

## Price list import

The export format **is** the import format — same columns, same order:

```
KODE | MERK | KATEGORI | TIPE_PRODUK | MOBIL | PART_NUMBER | DESCRIPTION |
QTY_PER_CTN | SATUAN_DASAR | HARGA | AKTIF | CATATAN
```

The routine update is export → edit the HARGA column → re-import. The pipeline is:

```
upload → store raw file forever → parse to staging (queued)
       → validate → diff preview → human approves → publish as new version
```

Nothing writes to live prices. Publishing inserts a new `price_list_versions` row; a price is
never `UPDATE`d.

The raw supplier workbook is messy in known ways, and `SupplierWorkbookParser` handles each one
deliberately: repeated headers mid-file, categories that exist only as title rows, mislabeled
headers (columns are mapped by position and validated, never by header text), phantom columns,
`KODE` cells holding two SKUs, `QTY/CTN` cells holding two values, and hundreds of blank
`QTY/CTN`. Anything ambiguous becomes a blocker for a human rather than a guess.

Two safety rules worth knowing before you touch the importer:

- **SKUs missing from a file are never auto-deactivated.** The default is leave-alone. A
  "this file is a full replacement" checkbox is the only way to opt in.
- **Safety brake:** if more than 20% of prices change, or any single price moves more than 50%,
  publishing requires a second confirmation that names the numbers.

## Roles

| Role | Can | Cannot |
|---|---|---|
| Sales | Create orders, see prices | Override credit limit, confirm payment |
| Warehouse | Pick, ship, print surat jalan | See prices or customer credit data |
| Finance | Confirm payments, manage credit + AR | Edit order line prices |
| Owner | Everything + audit log | — |

Whoever confirms a payment must not be able to edit the invoice amount. Every price override and
credit-limit override is logged with actor, old value, new value and timestamp.

## Conventions

- Migrations are additive. Never edit a shipped migration.
- Every money-affecting action writes to the audit log.
- Queue jobs must be idempotent — assume they run twice.
- User-facing strings are Bahasa Indonesia; code identifiers are English. Domain terms stay in
  Indonesian where that is what staff actually say (`surat jalan`, `faktur pajak`, `gudang`).

## Not in v1

Shipping-rate API integration · Coretax API integration · mobile app · real-time notifications ·
multi-currency · product reviews · recommendation engine · promo/voucher engine · public price
display.

## Before launch

- PSE Lingkup Privat registration with Komdigi via OSS → PB-UMKU.
- Kebijakan Privasi page (UU PDP 27/2022).
- Written terms of sale covering credit terms, late payment, returns, delivery.
- Nightly encrypted `pg_dump`, off-box. Test the restore.
