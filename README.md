# WebTransaction

B2B wholesale portal for automotive spare parts, Indonesian market.

Buyers are bengkel, toko sparepart and distributors — not retail consumers. Three surfaces sit
over one shared domain core: a public site (open, no prices), a buyer portal (authenticated,
per-customer prices), and an admin panel (staff, role-scoped).

All five build phases are code-complete: the admin panel and domain core, payment recording
and AR, the buyer portal with pilot onboarding, the public site, and reporting — plus the
post-build hardening (security audit, volume pass, go-live rehearsal). What separates the
code from a launch is the business's own checklist, visible on `php artisan launch:check`.

The deep documentation lives in `docs/`:

| Doc | What it answers |
|---|---|
| [docs/MAP.md](docs/MAP.md) | Every screen, domain class and decision — the codebase's own map |
| [docs/DEPLOY.md](docs/DEPLOY.md) + `deploy/` | A bare VPS to a running system, with the executable kit |
| [docs/GO-LIVE.md](docs/GO-LIVE.md) | The cutover sequence, rehearsed before it was written down |
| [docs/UAT.md](docs/UAT.md) | Per-role acceptance script |
| [docs/PILOT.md](docs/PILOT.md) | Running 3–4 friendly customers for two weeks |
| [docs/DEMO.md](docs/DEMO.md) | Seeding and walking through the demo data |
| [docs/BACKUP.md](docs/BACKUP.md) | Encrypted nightly backups, and the restore drill |

## Stack

Laravel 13 · Livewire · Filament 4 · PostgreSQL 16 · Redis (queue + cache). No payment
gateway — payments are recorded by finance against the bank statement.
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

### Showing it to somebody

`migrate --seed` gives you staff logins and empty screens — there is nothing to
demonstrate, because real prices arrive through a reviewed import rather than a
seeder. To get a business worth looking at:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoSeeder
```

Every dashboard queue fills, orders exist in every state, and the purchase
order carries a deliberate variance so the three-way match has something to
find. **[docs/DEMO.md](docs/DEMO.md)** is the ten-minute walkthrough, with the
logins and what to say at each step.

`DemoSeeder` refuses to run in production or on a database that already has
orders in it: it writes invented prices into append-only ledgers, and there is
no clean way back out.

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
| `app/Domain/Stock/InventoryValuation.php` | Moving-average cost, COGS, inventory value. |
| `app/Domain/Purchasing/GoodsReceiptPoster.php` | Goods in: stock rises, average cost moves. |
| `app/Domain/Purchasing/PurchaseOrderFlow.php` | Every purchase order transition. |
| `app/Domain/Purchasing/SupplierLedger.php` | Append-only money-out ledger. |
| `app/Domain/Purchasing/ThreeWayMatch.php` | Ordered vs received vs billed. |
| `app/Domain/Credit/CreditChecker.php` | Credit exposure and limit checks. |
| `app/Domain/Orders/OrderStateMachine.php` | Every order transition. |
| `app/Domain/Payments/PaymentLedger.php` | Append-only money ledger. |
| `app/Domain/Billing/InvoiceIssuer.php` | Confirmed order → invoice, from line snapshots only. |
| `app/Domain/Documents/` | Gapless per-period document numbering. |
| `app/Domain/PriceList/` | Tolerant importer, diff, versioned publishing. |
| `app/Filament/Widgets/` | The admin worklist queues. |
| `app/Filament/Actions/OrderTransitionActions.php` | Every order transition, shared by every screen. |
| `app/Http/Controllers/SuratJalanController.php` | The delivery note. No prices on it, by design. |
| `app/Filament/Portal/Widgets/` | The buyer portal landing screen. |
| `app/Support/BrandColors.php` | The company palette. |
| `app/Support/Branding.php` | The logo, and the wordmark fallback when there isn't one. |
| `config/perusahaan.php` | All public-site content — profile, partners, contact, roadmap. |

## The three surfaces

| URL | Who | Auth |
|---|---|---|
| `/` | Public — company profile, partners, contact, roadmap | none |
| `/admin` | Staff — orders, stock, billing, price lists | `web` guard, `users` |
| `/admin/pengiriman` | Warehouse — pick list, surat jalan, ship | `web` guard, warehouse role |
| `/admin/pesanan-pembelian` | Purchase orders + three-way match | `web` guard, Finance/Owner |
| `/admin/penerimaan` | Goods receipt — stock in, average cost | `web` guard, Finance/Owner |
| `/admin/tagihan-pemasok` | Supplier bills, PPN masukan, AP | `web` guard, Finance/Owner |
| `/portal` | Buyers — credit, invoices, order history, printable faktur | `customer` guard, `customer_users` |

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

Public-site content lives in `config/perusahaan.php`. The company name is real;
**the rest of the shipped text is placeholder and must be replaced before
launch** — address, phone, NPWP, NIB, and above all the joint-venture partners,
since naming a company in public is a claim about a real business relationship.

### The logo

Set `PERUSAHAAN_LOGO` to a path under `public/` — `images/logo.svg`, say — and
the mark appears in the public header, both panel sidebars and on the surat
jalan letterhead. Until that file exists, every one of those falls back to the
wordmark, so an unset logo looks deliberate rather than broken.
`App\Support\Branding` checks the file is actually there before emitting a URL:
a configured path pointing at an undeployed file would render a broken image on
the company's own shopfront.

SVG for preference — the mark is flat colour and has to stay crisp in a sidebar
and on a printed delivery note alike.

### Language

Bahasa Indonesia behind every login and on every printed document — admin
panel, buyer portal, surat jalan, faktur. The public site is English (2026-08):
it introduces the company to buyers and to the overseas suppliers and partners
it deals with, and its copy lives in `config/perusahaan.php`. The two legal
pages are the exception on the public site: they are instruments under
Indonesian law, stay in Bahasa Indonesia, and declare `lang="id"` themselves.

One copy of each sentence. The site was once bilingual with `['id' => ...,
'en' => ...]` pairs in config, and the two versions drifted; that is not
coming back. The only deliberate pair is the business hours — one string for
the Indonesian documents, one for the English site.

The app locale is `id` and stays there. Public copy lives in
`config/perusahaan.php` and is read through `App\Support\Perusahaan`.

Filament's own Indonesian translation has holes in it, and Laravel does not
fall back to English — it renders the key, so a table footer reads
`filament-tables::table.result_count`. The gaps are patched in `lang/vendor/`,
and `IndonesianTranslationCoverageTest` fails the build the next time an
upgrade adds an English key with no Indonesian one.

## Look and feel

Clean white surfaces, company blue `#073185` — the logo's own colour — and
company red `#DC2626`.

Two things to know before touching it:

- The blue has to sit at **shade 600** of the ramp in `BrandColors`. Filament
  paints solid buttons and active states from there, and `Color::hex()` will not
  do it: it keeps only the hue and applies a generic lightness curve.
- **Solid buttons lighten on hover, they do not darken.** `#073185` is dark
  enough that the usual darkening step was a luminance change of 0.012 — no
  feedback at all. Both the panel and the public site lighten to shade 500.

Red is not decorative anywhere in the panel: it means stock is short, an
invoice is overdue, or the action destroys something. Rows carrying a red
*value* get a red left edge so a manager cannot scroll past them. That only
keeps working if red stays scarce — please don't spend it on ordinary buttons.

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

Both panels and the public site set in Geist, self-hosted from `public/fonts`
(SIL OFL); the panels load it through Filament's `LocalFontProvider`, so no
surface fetches a font from a CDN and the privacy notice stays true. The panel
sidebars show the mark alone, as before (`logo-dark.svg` once dark mode is on).

The public site is deliberately light-only and declares `color-scheme: light`,
so a visitor on a dark-mode OS doesn't get dark browser chrome — scrollbars,
selects, autofill — drawn over a white page.

## The order lifecycle

```
draft  →  submitted  →  confirmed  →  awaiting_payment  →  paid  →  shipped  →  completed
  ↑            ↑             ↑                ↑              ↑         ↑           ↑
order       "Ajukan"     "Setujui"       "Tagihkan"    pelunasan   "Tandai   "Selesaikan"
 form                    prices lock,     invoice        di buku    dikirim"
                         stock held       terbit        keuangan   stock out
```

Every one of those transitions is available from the order list, the order's
own detail page, and the dashboard queue — they are the same action objects in
`app/Filament/Actions/OrderTransitionActions.php`, and each hides itself unless
it applies. They used to live only on the queues, which meant an order could be
found in the list and then not acted on.

Three of those steps are where the money is decided, and each does its work in
a single transaction:

- **confirmed** — every line snapshots its price, discount, DPP, PPN and price
  list version; credit is checked; stock is reserved under a row lock.
- **awaiting_payment** — the invoice is issued from those snapshots, printing
  the company bank account as the place to send the transfer.
- **paid** — reachable *only* through settlement in the payment ledger: finance
  records the transfer, cash or cleared giro, and when an invoice is covered the
  settlement advances the order itself.

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
4. **`paid` is set only by settlement in the payment ledger.** Never a browser redirect, never
   a controller flipping a flag. Finance records the money as an append-only entry; a covered
   invoice advances its own order.
5. **Unit of measure is modeled.** Order lines store both the ordered unit/quantity and the
   resolved base quantity; the ledger is always in base units.
6. **Money is BIGINT rupiah.** Never float.
7. **Stock is reserved at `confirmed`, decremented at `shipped`**, under a row lock. A
   scheduled job releases reservations on stale unpaid orders.

Tests exist for the five places where bugs cost money: price resolution, credit check, stock
reservation, payment settlement, tax calculation.

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
deliberately. The numbers below are measured from the real `PL_JAVA_IMPORT.xlsx`, not estimates:

| Quirk | Count | Handling |
|---|---|---|
| Repeated header rows mid-file | 56 | detected by shape, skipped |
| **Mislabeled headers** (rows 872, 884) | 2 | columns mapped by position, never by header text |
| Sheet names that aren't brands | 3 sheets | `MERK` column is the only authority |
| Category *and* product type as title rows | 4 + 35 | carried forward independently |
| Phantom columns | 183 wide | trimmed before parsing |
| Blank `QTY/CTN` | 724 | default 1, note in `CATATAN` |
| `KODE` holding 2+ SKUs | 22 | blocker |
| `QTY/CTN` holding 2+ values | 28 | blocker |
| `QTY/CTN` reading "FULL KIT" / "MINOR KIT" | 16 | note, text preserved — likely SET, not PCS |
| Zero prices | 4 | blocker |
| Line breaks inside cells | 2 | flattened |
| Effective date | none | operator supplies it; never invented |

**The mislabeled headers are the reason for the positional rule**, and the real file proves it:

```
row 872  MOBIL | KODE        | DESCRIPTION | PART NUMBER | HARGA | QTY/CTN | MERK
row 884  MOBIL | PART NUMBER | DESCRIPTION | QTY/CTN     | KODE  | HARGA   | HARGA
```

In both cases the data underneath is in the standard order — the *header text* is wrong. A parser
that trusted it would file part numbers as SKUs and prices as carton sizes for every row beneath,
silently, because every value would still look plausible.

**`QTY/CTN` is bounded, and that bound is load-bearing.** Fourteen rows carry a CV joint's
dimensions in that column (`26-22-55`, millimetres). Treated as a number, that is a carton size of
262,255 — and `qty_per_ctn` is trusted arithmetic, so ordering one dus would move a quarter of a
million units through the stock ledger. Hyphens now split like any other separator, and
`max_qty_per_ctn` catches whatever shape nobody has thought of yet.

One operational consequence worth knowing: a row that becomes a blocker is "missing from this
file", and missing SKUs are left alone by design. So a bad value already live on a product is
**not** corrected by a later import that blocks the row — it has to be fixed on the product.

Anything ambiguous becomes a blocker for a human rather than a guess. Tests run against a
synthetic workbook reproducing every shape above; the live price list is commercial data and is
not committed.

Two safety rules worth knowing before you touch the importer:

- **SKUs missing from a file are never auto-deactivated.** The default is leave-alone. A
  "this file is a full replacement" checkbox is the only way to opt in.
- **Safety brake:** if more than 20% of prices change, or any single price moves more than 50%,
  publishing requires a second confirmation that names the numbers.

## Roles

| Role | Can | Cannot |
|---|---|---|
| Sales | Create orders, see prices | Override credit limit, confirm payment |
| Warehouse | Pick, ship, print surat jalan | See prices, costs or customer credit data |
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

## The warehouse

`/admin/pengiriman` is the warehouse's own screen: what to pick, what is out
for delivery, what is done. Warehouse staff are the one role that cannot see
money, and this is where they live — the price columns are never selected on
it, so there is nothing to leak if a template changes.

**Surat jalan** prints from there, and from the order list, at
`/dokumen/surat-jalan/{order}`. It is a print-styled HTML page rather than a
generated PDF: no dependency, prints correctly from the shared machine in a
warehouse, and "Save as PDF" in the print dialogue produces an archival copy.
A PDF library can be added later without changing the page — the layout is
already the document.

**It carries no prices.** That is the point of the document as much as the
quantities: it is handed to a driver and then to whoever signs for the goods,
and neither is party to what this customer pays. `WarehouseWorkflowTest`
asserts no rupiah figure appears anywhere on it.

It only renders for an order that has actually committed stock. Before
`confirmed` nothing is reserved, so a delivery note would describe goods the
warehouse has not been told to set aside.

## Goods receipt and costing

Two gaps that made the system unable to describe its own inventory.

**Stock could only go down.** `StockLedger::record()` accepts any of seven
`MovementReason`s, and the application only ever wrote one: `Pengiriman`. There
was no screen and no code path by which goods arrived, so a warehouse drained
monotonically until the availability check started refusing orders — which makes
Phase 1, "staff enter real orders", impossible to actually run.

`/admin/penerimaan` is the document that fixes it. Draft until posted; posting
writes the movements and moves the average cost in one transaction, and a posted
receipt is never edited — a mistake is corrected with an opposing document, for
the same reason a stock movement is never updated in place.

**Nothing knew what anything cost.** There was no cost field anywhere in the
schema, so COGS, inventory value and margin could not be computed at all.

### Why cost lives on the movement

A movement records what happened at a moment. If the cost that applied at that
moment was never written down, no later migration can recover it — you would be
guessing at history and filing the guess as an accounting record. This was the
one gap that got more expensive every day the system ran.

`stock_movements.value_rupiah` is signed and follows the quantity: summing it
over a period is the movement of inventory value, and summing it over shipments
is COGS.

### Why a (quantity, value) pair

The obvious design stores a unit cost and recomputes it on each receipt. It
drifts: money here is integer rupiah, so every recomputation rounds, and after a
few hundred receipts the stated cost times the quantity on hand no longer equals
what was paid.

`product_costs` holds quantity and total value instead. Value is only ever added
to or subtracted from, never recomputed, and unit cost is derived when somebody
asks — so rounding happens once and never accumulates. `InventoryCostingTest`
asserts the consequence on deliberately awkward numbers: **everything paid in
comes back out as COGS, to the rupiah, by the time the shelf is empty.**

One average for the company rather than one per warehouse. Inventory is valued
for the entity, and it makes a transfer value-neutral by construction — no
shuffling of stock between buildings can invent or destroy value.

### COGS is frozen, not derived

A shipment stamps the average standing at that instant. A receipt arriving next
week moves the average for everything after it and changes nothing before it,
which is what stops last month's gross margin from shifting because somebody
bought stock today.

### Opening stock

Stock that predates costing sits in the ledger with no value, and is reported by
`unvaluedQuantity()` rather than counted as reconciliation drift — a check that
always cries wolf gets ignored on the day it is right. The cure is an
opening-balance receipt: count the shelf and enter what is on it at its known
cost, from a supplier record standing for "saldo awal".

Until that is done, inventory value is understated and shipments of that stock
are recorded with a **null** cost rather than a zero one. Zero reads as infinite
margin, and nobody notices until it is in front of the owner.

### Who sees cost

`canSeeCost()` is Finance and Owner — one role tighter than `canSeeCreditData()`,
and the missing role is Sales. What a customer pays is a salesperson's job; what
we paid is not, because cost plus selling price is margin, and margin in the
hands of whoever negotiates the discount changes how the discount gets
negotiated.

A named compromise: the person who physically counts the cartons is warehouse
staff, and they cannot enter this document, because it carries what we paid.
Splitting it — warehouse records quantities, finance attaches costs and posts —
is the right shape and is not built. Until it is, receipts are entered from the
paperwork rather than from the loading bay.

## Purchase to pay

`PO → penerimaan barang → tagihan pemasok → pembayaran`, the buy-side mirror of
order to cash. It earns its keep at the joins rather than in any one document: a
purchase order alone is a wish, a receipt alone cannot tell a short delivery
from a complete one, and a bill alone is whatever the supplier decided to
charge.

### The purchase order

```
draft → dikirim → selesai
           ↓
      dibatalkan
```

**Sending locks the lines.** From the moment an order goes to a supplier the
document records what was agreed; receiving against a line somebody edited
afterwards would compare deliveries to a moving target.

Receiving registers against the PO line *inside the receipt's own transaction*,
so the received quantity and the stock movement land together or not at all, and
the order closes itself once nothing is outstanding.

Closing short is allowed and records what never came — a supplier discontinuing
a part mid-order is ordinary, and that number is the only evidence left
afterwards. Cancelling is refused once goods have arrived, because it would
leave the receipt pointing at a document saying nothing was ever ordered.

A receipt with **no** PO behind it still works. Stock sometimes simply turns up
— an urgent counter purchase, or the opening balance — and refusing to record
that pushes people into recording it nowhere.

### The printed order

`/dokumen/pesanan-pembelian/{po}` — the third print document, and the first that
travels *outward*. The surat jalan goes with our goods and the faktur goes to
our customer; this one lands in somebody else's inbox and asks them to ship
something. Until it existed, "Kirim ke pemasok" moved a status in our database
and put nothing anywhere else.

**A draft cannot be printed.** Nothing has been agreed, the lines are still
being edited and the total is not fixed — sending one would create an
obligation the system does not believe exists. A closed or cancelled order
prints with its status stamped across it, so it can go in the file without ever
reading as live.

Two things on it are load-bearing rather than decorative:

- **"Cantumkan nomor pesanan PO-… pada surat jalan dan faktur Anda."** That
  reference is what makes the three-way match possible when the goods arrive.
  Without it somebody has to guess which order a delivery belongs to, and
  guessing is how a delivery gets matched against the wrong one.
- **Prices stated as excluding PPN.** A supplier who reads the total as
  VAT-inclusive invoices for 11% less than we agreed, and it is only found at
  the match.

Quantities print in both the ordered unit and base units — the supplier ships
cartons and the ledger counts pieces, and printing only one of them is how a
delivery arrives ten times too small.

### Supplier bills

Shaped exactly like the customer invoice, for the same reasons: figures summed
from line snapshots, the total fixed at posting, and never editable afterwards
**by anybody, including the owner**. Payment appends to a ledger that never
touches the total. That is the control, in the opposite direction: whoever pays
cannot move the amount owed.

PPN on a purchase is **pajak masukan** — input VAT creditable against the output
VAT on our sales — so it is real money rather than a formality, and it is
computed per line through the same `TaxCalculator` the sell side uses. The
bills table greys the figure out when there is no faktur pajak number behind it,
because PPN that cannot be credited is not money back.

### The three-way match

Ordered against received against billed, on the PO's own row. It catches the
ordinary failures rather than exotic fraud: a short delivery billed in full, a
price that moved after the goods were valued, the same delivery billed twice.

**It reports and does not block.** A variance is usually a conversation with the
supplier, and a control that refuses to let people record what actually happened
gets worked around — which loses the record entirely. A partly-delivered order
is deliberately *not* flagged: that is work in progress, and flagging it would
bury the real ones.

**Price variance is not posted to inventory.** Goods stay valued at what the
receipt said they cost. Doing it properly needs a purchase price variance
account and there is no general ledger to put one in, so the modal says so on
its face rather than letting somebody assume it was handled.

## The faktur

The surat jalan's opposite number, and deliberately its exact inverse. That
document is all goods and no money and only the warehouse may print it; this
one is all money and no goods and the warehouse may not open it at all.

It prints from the invoice list, from the order screen, and — this is the point
— from the buyer's own portal, so a customer can send their accountant a copy
without asking anyone. Two routes, one for each guard:

| Route | Guard | Rule |
|---|---|---|
| `/dokumen/faktur/{invoice}` | `web` | `canSeeCreditData()` — Sales, Finance, Owner. Not Warehouse |
| `/portal/dokumen/faktur/{invoice}` | `customer` | The buyer's own company only |

Two routes rather than one with `auth:web,customer`, because a single route
would authenticate on whichever guard answered first and the controller would
then have to work out what kind of visitor it was talking to. One route, one
guard, one rule — and both render the same document from the same figures, so a
customer and the salesperson on the phone are looking at the same page.

A buyer asking for another company's invoice gets a **404, not a 403**: a 403
confirms the invoice exists, which is a slow way of telling a customer how much
business a competitor is doing.

**DPP and PPN appear on every line**, not only on the total. That is a tax rule
rather than a layout preference — under PMK 131/2024 the DPP is 11/12 of the
selling price, and summing rounded lines is not the same number as rounding a
summed total. The columns are ordered so each one foots to a row in the totals
block; an invoice whose columns do not add up is an invoice somebody has to
phone about.

The total is also written out in words (`App\Domain\Terbilang`), which is the
line a bookkeeper checks the digits against.

**It is not a Faktur Pajak**, and it says so on its face. This is the commercial
invoice; the tax document is issued through Coretax and comes back with an NSFP,
which is stored on the invoice record and printed here once it exists. The CSV
export that feeds Coretax is not built yet.

## The legal pages

`/kebijakan-privasi` and `/syarat-penjualan`, both public, both linked from the
footer of every page. The terms are also linked from the checkout dialogue,
because they say a buyer accepts them by placing an order and that is only true
if the buyer can reach them from the screen where they place it.

**Both are a draft and need a lawyer before launch.** They are written to be
accurate about what this software actually does — which is the part a lawyer
cannot check for you — but that is not the same thing as legal sufficiency.

### The privacy notice renders from the schema

A privacy policy is a factual claim about a database. Databases change every
week; policies get written once and quietly become false. UU PDP Pasal 21
requires the notice to state the *types* of data processed, so "we may collect
information about you" is not a policy — it is an admission that nobody checked.

So the data lives in `app/Support/Legal/DataInventory.php`, the notice renders
from it, and `LegalPagesTest` reads the live schema and fails the build if any
column of any inventoried table is unclassified:

```
New column(s) on `customer_users` that the privacy notice does not account
for: nomor_ktp.
Add each one to App\Support\Legal\DataInventory under `personal` if it holds
or identifies personal data, or under `bukan` if it does not.
```

Adding a column is therefore a two-line change, and forgetting the second line
is a red build rather than a false public statement.

One thing the notice would have got wrong without an audit: the audit log
stores an **IP address**, which is an identifier under UU PDP. It is disclosed
rather than filed under "technical data".

The cookie section is also asserted — a test compares it against the cookies the
home page actually sets, because the first draft claimed there were none and
there are two.

### What the terms promise, the code has to honour

Price binds at `confirmed`, because that is when the snapshot is taken. The
stock-reservation window is read from the same setting `ReleaseStaleReservations`
obeys, and a test asserts the two agree.

## Before launch

The launch checklist is not this file — it is `php artisan launch:check` and the
Owner-only **Kesiapan peluncuran** screen, where most items check themselves
against the live system and the rest (PSE registration, the lawyer's reading,
the accountant's confirmation, a rehearsed restore) are attested by name and
date. The full cutover sequence is [docs/GO-LIVE.md](docs/GO-LIVE.md), executed
end-to-end against an empty database before it was written down.
