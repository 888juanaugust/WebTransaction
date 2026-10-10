## Project

**Central** is the company system of an Indonesian B2B wholesaler of automotive spare
parts: a registered PT with an NIB, selling on credit to bengkel, toko sparepart and
distributors — never to retail consumers. Brands carried: YUHOLI, OSBORN, ASTRO, STAVO,
STAVIX, SERVO, BDAX. Categories: HYDRAULIC PART, SUSPENSION PART, ELECTRIC PART, BEARING
PART. Three surfaces over one set of books:

- **Admin panel** (`/admin`) — staff, role-scoped; worklists first, CRUD behind them
- **Buyer portal** (`/portal`) — authenticated customers, their prices, reorder, invoices
- **Public site** — open, indexed, bilingual, never shows a price

Central runs on the **August ERP base** (`888juanaugust/AugustERP`): a standard, modular
ERP for trading companies — accounting, inventory, purchasing, sales, cash and bank, fixed
assets, tax and reports — with a workspace shell, a module registry, a posting layer and
access groups. The base was copied in once (the commit that added `config/client.php`); it
is not merged again, and the August ERP repository is never changed from here. What this
repository held before that commit is the previous system, kept in history for reference.

The functional standard of the base is `docs/standard/`: one page per module group, one
section per screen, generated from the code by `php artisan erp:standard`, with
hand-written notes in `docs/standard/_notes/`. A change to a screen is a change to the
standard; regenerate the pages in the same commit (`StandardDocsTest` fails otherwise).
Central's own screens appear on those pages too.

**Footprint rule.** The commercial product the base was once studied from is never named:
not in code, identifiers, docs, commit messages, the UI or the repository description.
`FootprintTest` greps the working tree and the history since the base came in.

## Architecture

**The base stays the base; Central lives in its own layer.** Everything Central adds goes
under `app/Client` and is registered in `config/client.php`:

| Where | What |
|---|---|
| `config/client.php` | `modules` (Central's modules), `screens` (their screen keys), `features` (modules to start with), `theme` (panel colours) |
| `app/Client/Modules/` | Modules implementing `App\Modules\Module` (extend `App\Modules\BaseModule`): their screens, morph names, posting hooks, default seeders, commands and schedule |
| `app/Client/Screens/` | String-backed enums implementing `App\Domain\Access\ScreenKey`, one case per screen, values starting `client__`. A value is stored in access rights, so it never changes once in use |
| `app/Client/Filament/Resources`, `app/Client/Filament/Pages` | Central's screens, discovered by the panel; a resource extends `ErpResource` / `MasterResource` and returns its `ScreenKey` from `menuKey()` |
| `app/Client/Models/`, `app/Client/Seeders/` | Central's models and seeders (a module's `defaultSeeders()` runs them on install and `db:seed`) |
| `app/Client/database/migrations/` | Central's migrations, run with the base's; dated after the base migration they build on |
| `app/Client/lang/<locale>.json` | Central's strings; they override the base's |
| `app/Client/ClientServiceProvider.php` | Boots last: bindings that replace a base service (a `PriceResolver` subclass, say), extra blockers or ledger writers through `App\Modules\ModuleContext`, a second panel |
| `tests/Feature/Client/` | Central's tests |

A base file is edited only where the layer cannot reach, and the edit is named in the
sub-project's spec: `config/auth.php` (a second guard), `app/Filament/Modul.php` (a new
group), `App\Domain\Numbering\TransactionType` and `PatternToken` (new document types, a
branch token), `App\Domain\Printing\Printable` (new printables),
`App\Domain\Pengaturan\PreferensiKey` (a Central module's on/off switch). Everything else
is overridden from the layer, never patched in place.

**Reuse before port.** The base already has: access groups with special rights and the
segregation-of-duties rule, the credit check with notice and freeze days, sales extras
(check-ins, commissions, targets), giro, down payments, quotation → order → delivery →
invoice → receipt, the purchasing chain, stock opname and transfers, minimum stock, fixed
assets, the Coretax bulk-import XML and the legacy CSV, bank statements and reconciliation,
periods, the report catalogue, PDF printing, master imports. Central configures these; it
does not rebuild them.

**Roadmap.** `docs/ROADMAP.md` lists the sub-projects that bring the previous system's
features onto the base. Each gets a spec in `docs/superpowers/specs/` and a plan before
any code.

## Stack

- Laravel 13, Filament 5, PHP ^8.3 locally, 8.4 in CI
- PostgreSQL 16 only (row locks, partial unique indexes, jsonb). Never SQLite, not even in tests
- Redis for queue and cache; supervisor-managed workers in production
- Node 22 for the Vite build, the UI smoke script (`npm run smoke`) and the i18n tools
- No payment gateway: credit sales are settled by transfer, cash or giro, recorded by Finance
- Single VPS, Jakarta. Caddy for TLS.

## Modules

`config/modules.php` lists the base modules, each a class under `app/Modules/` implementing
`App\Modules\Module`: its key, the Features preference that switches it (or null for always
on), the `MenuKey`s it owns, its morph-map aliases, what it wires in `boot()` (ledger
writers, blockers, fulfilment chains), its default seeders, commands and schedule.
`ModuleRegistry` answers which are on; `ErpResource` and `ErpPage` refuse and hide the
screens of a module that is off; the morph map stays complete so ledgers and logs holding
an off module's rows still read. Central's modules follow in `config/client.php`.

Sidebar groups are the `Modul` enum (ten groups); a module may own screens across groups.
Optional base modules: fixed assets, tax, approval rules, budgets (on); sales extras (on
for Central: check-ins, commissions, targets); payroll, departments and projects (off).
Branches and currencies screens follow the Multiple branches / Multiple currencies
preferences; Central runs several branches (cabang) and one currency.

**Adding a module:** the class, its entry in `config/client.php`, its screen-key cases
with `modul()` and `sort()`, its resources and pages (extending `ErpResource` / `ErpPage`,
form tabs with an explicit `->icon()`), its seeders returned by `defaultSeeders()`, and
the notes in `docs/standard/_notes/`. `ScreenRouteTest` and `ModuleToggleTest` cover every
screen's ownership, reachability and switch.

## Language

**The product speaks English and Indonesian.** The company's language is Preferences →
Other → Language (`erp:install --locale=id`); each user may choose their own on their
profile, and `SetLocale` applies it to the app and Carbon on every panel request. Anything
sent to a customer (the tax invoice email and its PDF) goes in the company's language
through `Locales::using()`. Every string the UI shows passes through `__()`; the English
text is the key, so no `lang/en.json` is needed, and `lang/id.json` holds the Indonesian.
Grouped keys live in `lang/{en,id}/{menu,fields,status}.php`; `Format::code($value, $group)`
reads the status groups and `Format::monthName()` / `Format::months()` give month names.
Every field, entry, column and filter gets an explicit `->label(__('…'))`: Filament's
default label is made from the column name and is never translated. Helpers take the
label already translated (`NumberFields::make($type, __('Invoice No.'))`).

After adding strings run `node tools/i18n/wrap-literals.mjs` (wraps literals a developer
left bare) and `node tools/i18n/extract-strings.mjs id` (adds the new keys to
`lang/id.json` empty, to translate). `TranslationGuardTest` fails the build on a literal
it would have wrapped; `IndonesianTranslationTest` fails it on a key without Indonesian,
on a field without a label, and on English text on any screen rendered in Indonesian.
Buttons are verbs ("Save order", "Record payment"), never "Submit".

Numbers and dates follow the Indonesian convention through `App\Domain\Shared\Format`
(`Rp 18.450.000`, `17 Oct 2026` in tables (`17 Okt 2026` in Indonesian), `17/10/2026` in
inputs), with the base currency's symbol from `Format::symbol()`; the separators and date
order are their own preferences. Code identifiers are English; Indonesian domain words
without an English equivalent in daily use (`giro`, `faktur pajak`, `NPWP`, `NITKU`,
`surat jalan`, `cabang`, `gudang`, `dus`, `karton`) stay as they are. The public site's
own copy lives as `['id' => …, 'en' => …]` pairs, and a test fails the build on a pair
with a side missing; its two legal pages stay Indonesian whatever the visitor chose.

## Design

`docs/design/DESIGN.md` is the visual system; `resources/css/filament/admin/theme.css`
implements its tokens and `AdminPanelProvider` its settings, with the colours overridable
in `config/client.php`. Components use tokens, never raw hex. Geist is self-hosted; no
font or asset is loaded from a third party. Light by default; each user picks Light, Dark
or System in the user menu (dark tokens under `.dark` in `theme.css`; prints stay white).
Motion uses the token scale in `theme.css` (DESIGN.md, Motion); the one JavaScript
animation is the tab strip's GSAP Flip (`resources/js/workspace-motion.js`, bundled by
Vite).

**The shell is the workspace** (`App\Filament\Pages\Workspace`, the panel's home): a dark
icon rail of the module groups, a tile menu per group built by `App\Filament\Shell\Menu`
(tiles coloured by `MenuKey::kind()`), and every screen opened as a live tab in its own
frame. Filament's sidebar is off (`->navigation(false)`). Screens need nothing special to
live in a tab: `resources/js/shell/bridge.js` hides the chrome in a frame, opens links to
other screens as new tabs and reports the tab's title. Form tabs stand down the left side as
icons; a base form tab name has its icon in `App\Filament\Support\SideTabIcons`, a Central
tab sets `->icon()` itself (`ShellMenuTest` fails otherwise). `npm run smoke:shell` drives
the shell in Chromium. The buyer portal and the public site are built from the same
tokens and typeface; they are not the workspace.

**UI work uses the design skills in `.claude/skills/`**: `impeccable`,
`make-interfaces-feel-better`, `transitions-dev`, `transitions-polish` and the eight
`gsap-*` skills (sources and licences in `.claude/skills/SOURCES.md`). Before a session
builds or changes anything a person sees (a website page, a panel screen, a print view,
CSS or motion), it makes sure they are loaded and uses the ones that fit. A session opened
in this repository has them already. A session opened in another repository installs them
first, with `mkdir -p ~/.claude/skills && cp -r <this repository>/.claude/skills/*/
~/.claude/skills/`, and runs Impeccable's launcher with the three `env` values of
`.claude/settings.json` exported: they switch off its update check and telemetry, and stay
set. This file wins over any skill: tokens from DESIGN.md, Geist only and nothing loaded
from a third party, both themes checked, every string through `__()`, and the content
security policy. Motion respects `prefers-reduced-motion`. GSAP is added only when CSS
cannot do the job, and only as an npm dependency bundled by Vite, never from a CDN.

## Invariants

If a change appears to require breaking one, stop and ask.

1. **Postings are derived from documents, and only the posting layer writes them.** A
   document (invoice, receipt, delivery, adjustment, journal voucher…) is what a person
   edits. Its postings (`journal_entries`/`journal_lines`, `stock_movements`,
   `payment_allocations`) are regenerated from it by one posting service, keyed by a stable
   `posting_key`. Never touch a ledger table from a controller, form, report, seeder or raw
   SQL. Cached columns (stock on hand, reserved, average cost, settled amounts) must always
   be reconstructible from the ledgers. Stock reservations are a ledger of their own
   (held / released / consumed rows), never a column updated in place.
2. **Documents may be edited and deleted**, subject to access rights, the closed-period lock
   on both the old and the new date, and blockers (reconciled, settled, referenced by a
   later document). Every edit or delete is recorded in `audit_logs` and
   `document_revisions` (before and after), which are strictly append-only.
3. **Money is BIGINT rupiah.** Never float, never DECIMAL for totals. Unit prices may use
   DECIMAL(18,4) where fractions are unavoidable, rounded per line.
4. **Tax is per line.** Tax base and tax are computed and stored on every line, with the
   line's tax code; a document's totals are sums. The 12 % VAT with an 11/12 base (PMK
   131/2024, Coretax transaction code 04) is one tax code, not a constant. Confirm any
   change to tax logic with the accountant.
5. **Stock is per warehouse, costed by moving average, and every movement carries its
   document date.** A back-dated or edited document re-costs what came after it, never
   before the first open period. Stock is **reserved when an order is approved and leaves
   when it is delivered**; a job releases the reservations of stale unbilled orders.
6. **Units are modelled.** An item has a base unit (PCS or SET) and any number of other
   units with conversion ratios (`qty_per_ctn` for cartons); document lines store the
   entered unit and quantity and the base quantity; the stock ledger is always in base
   units.
7. **`paid` is set only by settlement.** An invoice is paid when the allocations of payment
   documents reach its total; never by a flag flipped elsewhere, never by a browser
   redirect. There is no payment gateway: Finance records each transfer, cash or cleared
   giro as a receipt; the money is the actor.
8. **A branch is a tag in one set of books**, on documents and journal lines. One ledger,
   one stock, one average cost, one vendor list. Central's branches are its cabang: each
   has warehouses, document numbers carry the branch, and a customer's exposure and
   aging aggregate across branches while branch-wide totals stay scoped.
9. **Pricing is one function.** `PriceResolver` returns the price and the reason it
   resolved that way; cart, order, invoice and quotation all call it, and order lines
   snapshot the result (unit price, discount, tax base, tax, price-list version, reason)
   when the order is approved. Never join to a live price list when rendering history.

## Order flow

```
draft → awaiting approval → approved → delivered → invoiced → paid
                ↓               ↓
            rejected         expired (reservation released)
```

Every transition is a logged event with actor and timestamp — the base's approval engine,
fulfilment statuses and settlement, never a flag flipped in place. Approval = the
customer's marketing seat or the Owner (Sales never approves). Approval snapshots prices,
runs the credit check and reserves stock in one transaction, all or nothing. When the
goods are scattered across warehouses the order is split into one piece per shipping
warehouse — home branch first, then the fullest foreign warehouse — each piece numbered in
its warehouse's branch and linked by `split_parent_id`; a customer whose home warehouse
holds nothing is re-homed, not split.

## Roles and business rules

Access groups hold the five rights per screen plus special rights; a user belongs to
groups, carries per-user grants or revocations, and is limited to assigned branches and
warehouses. Central's seeded groups are its roles:

| Role | Can | Cannot |
|---|---|---|
| Sales | Check-ins, orders for own customers, see prices, file pelunasan-piutang claims, returns and expense claims for own customers, customer insight | Approve credit, record payment, see cost, verify anything they filed |
| Marketing | **Global — reads every branch.** Approve/reject orders of own customers, watch their debt, file pelunasan claims, erase draft orders | Set prices, record payment, see cost |
| Purchasing (pembelian) | Stock work, catalogue, price list, stock statistics incl. cost, post returns, the purchasing chain (orders, receipts, returns, vendors, vendor prices) | See customer credit data; purchase invoices and vendor payments (Finance's) |
| Warehouse (gudang) | **Bound to one warehouse, one active account per warehouse.** Its warehouse's fulfilment queue, pick list, surat jalan, deliver | Anything outside its warehouse; cost; credit; catalogue; orders |
| Finance (keuangan) | Record receipts and payments, verify claims, manage credit and AR, books, month-end close, fixed assets, commission rates and targets, tax export | Edit prices, issue credit notes, reopen a closed period |
| Owner (Administrator) | Everything + activity log, branches, staff, teams | Verify a claim they themselves filed |
| Customer | The buyer portal only: a `customer_users` login on the `customer` guard, invited from Buyer Accounts | The staff panel |

Code reads a role through `App\Client\Access\CentralGroups` by the group's `role_key`
(administrator, finance, purchasing, warehouse, marketing, sales, portal), never by its
name: the Owner may rename a group (Keuangan, Pembelian, Gudang, Penjualan) on the Access
Groups screen; a role group cannot be deleted. `CentralGroupSeeder` shapes the groups
once; `central:reshape-groups` re-applies the matrix to an installed company, audited.
The base's Accounting group stays as the base seeds it and is not a Central role.

One sales + one marketing form the **team** of a customer (`sales_user_id` /
`marketing_user_id`, assigned only by the Owner through `TeamAssigner`, audited). The
sales must belong to the customer's branch; marketing is global.

**Hard rules.** Whoever records a payment never edits the invoice amount. Whoever is paid
on the sale never approves its credit. Whoever sets the price neither approves credit nor
records money. Whoever files a claim (pelunasan, return, expense) never verifies it — two
keys, two people, the Owner included. Every override is logged with actor, old value, new
value, timestamp.

**Debt terms.** Invoice due date defaults to 30 days. Aging counts from the issue date:
notice to customer and team at 120 days, hard freeze — no new transactions — strictly
after 150 days, lifted the moment the aged invoice settles. Derived arithmetic through
the base's `CreditCheck` preferences, never stored state.

Business rules are preferences on the Business Rules tab, read through
`App\Domain\Pengaturan\BusinessRule` and `CreditCheck`, never hard-coded: Sales Order
Approval (on for Central), Segregation of Duties (on; switching it off is audited), Allow
Negative Stock (off), credit notice and freeze days (120 / 150). A new rule defaults to
today's behaviour, so the suite stays green.

What the browser sends is never trusted for money: selling prices, discounts, tax terms,
the exchange rate and charges are checked on the server (`SellingPriceGuard`), a pulled
line must come from its upstream kind with the same item and unit (`SourceLineGuard`), a
receipt's price is set on the server, and cost and credit data never reach a page without
the right. A create page opened from another document (`?source=`) takes it only when the
user may see it (`SourceDocument`). A buyer's quantity in base units is always derived
from the item, never taken from the request.

Personal data (see `docs/PRIVACY.md`): a person's national ID, tax ID and bank account
number are `encrypted` casts; the activity log never holds an encrypted or hidden value.
A new personal field of that kind gets the same cast and joins the export in
`App\Domain\Privacy\PersonalData`.

## Price list

The export format **is** the import format: `KODE | MERK | KATEGORI | TIPE_PRODUK | MOBIL
| PART_NUMBER | DESCRIPTION | QTY_PER_CTN | SATUAN_DASAR | HARGA | AKTIF | CATATAN`.
`KODE` is the key. Pipeline: upload → raw file stored forever → parse to staging (queued)
→ validate → diff preview (new, changed with old → new and %, unchanged, missing from
file, errors) → human approves → published as a new version. Never write to live prices;
never UPDATE a price, insert a version. SKUs missing from a file are left alone and
flagged; only the "full replacement" checkbox deactivates them. Safety brake: more than
20 % of prices changed, or any single price moved more than 50 %, needs a second, typed
confirmation. Supplier workbooks are messy (repeated and mislabelled header rows,
category as a title row, split `KODE` cells, double `QTY/CTN`): map columns by position,
route blockers to review, import notes with an annotation.

## Priorities

Buyer portal, after login, in this order: reorder the last order (quantities editable),
available credit shown persistently, catalogue with the customer's prices, order history
with invoice and surat jalan PDFs, open invoices with due dates. B2B buyers restock the
same 15–20 SKUs; they do not shop.

Admin home is worklists: orders awaiting approval (credit + stock inline), accounts
awaiting approval, receipts not yet allocated, approved orders ready to pick, invoices
overdue by age. Generic CRUD exists behind these for corrections only.

**Not in v1 — do not build:** payment-gateway integration, shipping-rate API, Coretax API,
mobile app, real-time notifications, multi-currency, product reviews, recommendation
engine, promo/voucher engine, public price display.

## Compliance

- **PSE Lingkup Privat** registration with Komdigi via OSS → PB-UMKU before customers
  use the portal. Free.
- The public site carries a Kebijakan Privasi page (UU PDP 27/2022) and written terms of
  sale (credit terms, late payment, returns, delivery). Both stay Indonesian.

## Seeds

- `database/seeders/System/`: what the code relies on (administrator, branch, currency,
  chart of accounts, numbering series, the core masters). Always seeded.
- `database/seeders/Defaults/`: what a company usually wants and edits (banks, tax codes,
  payment terms, FOB, units, print layouts, access groups), plus each module's own defaults
  through `defaultSeeders()`. Seeded for the modules that are on.
- `database/seeders/Demo/`: a neutral demo company. Only on request. Never real data.
- `app/Client/Seeders/`: Central's own defaults (its access groups, brands, categories,
  branches), returned by its modules' `defaultSeeders()`.

`DatabaseSeeder` = System + Defaults + enabled modules' defaults. Tests build their sample
customer, vendor, item and salesperson from `Tests\Support\Fixtures`, or `seedDemo()`.

## Installing

`php artisan erp:install` migrates, seeds the system tables, records the company (name,
address, tax ID, fiscal year), sets the base currency (IDR), switches the optional modules
on or off (`--enable`, `--disable`, else `config('client.features')`), creates the first
administrator, seeds the defaults of the modules that are on and, when asked, the demo
company. It prompts for what the options leave out, refuses a second run without
`--force`, and `--fresh` drops every table first (never in production).

## Conventions

- Migrations are additive. Never edit a shipped migration.
- Every money-affecting action writes to the audit log, rows a master holds included
  (`RecordsChildActivity`); a bulk change (rights, memberships) logs what changed.
- Queue jobs are idempotent; assume they run twice.
- Tests required for: posting (journal balance, stock, allocations), price resolution,
  credit check, stock reservation, settlement, tax per line, cost recalculation, period
  lock and blockers, access rights, module switches. These are where bugs cost money.
- Before committing: `php artisan test`, `vendor/bin/pint --test`, and `php artisan
  erp:standard` when a screen changed. CI is `.github/workflows/laravel.yml` (PHP 8.4,
  PostgreSQL 16, Pint, `composer audit`, `npm audit`). Production setup is
  `docs/DEPLOY.md`. The cloud session hook `.claude/hooks/session-start.sh` brings up
  Postgres and Redis, installs dependencies and runs `erp:install --demo` on a first
  session.
- Backups: nightly `pg_dump`, encrypted, off-box. Test restore before launch.
- Commit messages say what changed for the product, never which tool or model wrote them.
