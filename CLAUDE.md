## Project

B2B wholesale portal for automotive spare parts, Indonesian market. Registered PT/CV with NIB.
Buyers are bengkel, toko sparepart, and distributors — **not** retail consumers.
Brands carried: YUHOLI, OSBORN, ASTRO, STAVO, STAVIX, SERVO, BDAX.
Categories: HYDRAULIC PART, SUSPENSION PART, ELECTRIC PART, BEARING PART.

Three surfaces over one shared domain core:

- **Public site** — open, indexed, no prices shown
- **Buyer portal** — authenticated, per-customer prices, ordering
- **Admin panel** — staff, role-scoped

## Stack

- Laravel + Livewire + Filament
- PostgreSQL
- Redis (queue, cache) + supervisor-managed workers
- Xendit for payments (fixed Virtual Account)
- Single VPS, Jakarta. Caddy for TLS.

## Language

Bahasa Indonesia is the primary UI language. Domain terms stay in Indonesian where that's
what staff and buyers actually say: `surat jalan`, `faktur pajak`, `harga`, `kode`, `merk`,
`gudang`, `dus`, `karton`. Code identifiers in English; user-facing strings in Indonesian.

---

## Invariants — do not violate these

These are load-bearing. If a change appears to require breaking one, stop and ask.

### 1. Stock and money are append-only ledgers

Never `UPDATE products SET stock = stock - n`.
Insert into `stock_movements` (sku, warehouse_id, qty_signed, reason, reference_type,
reference_id, actor_id, created_at). Current stock is a cached column updated inside the
same transaction, and must always be reconstructible by summing the ledger.

Same for `payment_entries`. Never mutate a payment row; insert a reversing entry.

### 2. Pricing is one pure function

`resolvePrice(company, sku, qty, date)` returns price + the reason it resolved that way.
Cart, order confirmation, invoice, and quote all call it. Never duplicate price logic —
not in the admin panel, not in a report, not in an export.

### 3. Order lines snapshot their price

At `confirmed`, copy unit price, discount, DPP, PPN, and `price_list_version_id` onto the
line row. Never join to the live price list when rendering a historical order or invoice.

### 4. `paid` is set only by the gateway webhook

Never by a browser redirect. Never by a controller responding to a user action.
Webhook handling: verify signature → insert raw payload into `webhook_events` with the
gateway event id as a UNIQUE key → return 200 immediately → dispatch a queue job.
Idempotency comes from that unique constraint. Never process inline.

### 5. Unit of measure is modeled, not assumed

Every SKU has a base unit (PCS or SET) and `qty_per_ctn`. Order lines store **both** the
ordered unit/quantity and the resolved base quantity. Stock ledger is always in base units.

### 6. Money is BIGINT rupiah

Never float. Never `DECIMAL` for totals. Unit prices may use `DECIMAL(18,4)` where
fractional rupiah is unavoidable, rounded to whole rupiah at the line level.

### 7. Stock is reserved at `confirmed`, decremented at `shipped`

Take `SELECT ... FOR UPDATE` on stock rows inside the confirming transaction.
A scheduled job releases reservations on stale unpaid orders.

---

## Order state machine

```
draft → submitted → confirmed → awaiting_payment → paid → shipped → completed
             ↓           ↓              ↓
         rejected    rejected        expired
```

Every transition is an explicit logged event with actor and timestamp — never a boolean
flag flipped in place. Transitions live in a dedicated state machine class, not scattered
across controllers.

---

## Roles

Reorganised 2026-08 for the credit-sales operation: customers buy on account,
every order needs marketing's approval, and debt is watched per customer by
the team in charge of them.

| Role | Can | Cannot |
|---|---|---|
| Sales | Store visits, order for customers, see prices | Approve credit, confirm payment, see cost |
| Marketing | Approve/reject pending orders, watch their customers' debt, initiate debt removal | Set prices, confirm payment, see cost |
| Inventori | Stock work, catalogue, price list, stock statistics (incl. cost) | See customer credit data |
| Finance | Confirm payments, verify debt removals, manage credit + AR, books | Edit prices, issue credit notes |
| Owner (admin) | Everything + audit log, regions, staff, teams | — |

One sales + one marketing form the **team** in charge of a customer
(`companies.sales_user_id` / `marketing_user_id`, assigned only by the Owner
through `TeamAssigner`, audited). Both must hold the right role and belong to
the customer's region.

**Hard rules:** whoever confirms a payment must not be able to edit the invoice
amount. Whoever is paid on the sale must not approve its credit (Sales cannot
approve orders). Whoever sets the price neither approves credit nor confirms
money. Log every override with actor, old value, new value, timestamp.

---

## Tax (PPN)

Headline rate is 12%, but for ordinary non-luxury goods the effective burden stays at 11%
because the DPP is `11/12 × harga jual` (PMK 131/2024). In Coretax this is transaction
code 04, not 01.

- Compute and store DPP and PPN **per line item**, never only on the order total.
- Buyer company record holds `npwp`, `nama_wajib_pajak`, `alamat_pajak`.
- Faktur output is a **CSV export matching Coretax import format**. No API integration.
- Store the returned NSFP back onto the invoice record.

Confirm specifics with the accountant before changing any tax logic.

---

## Price list import/export

The export format **is** the import format. Same columns, same order. Routine update is:
export → edit HARGA column → re-import.

Canonical columns:

`KODE | MERK | KATEGORI | TIPE_PRODUK | MOBIL | PART_NUMBER | DESCRIPTION |
QTY_PER_CTN | SATUAN_DASAR | HARGA | AKTIF | CATATAN`

`KODE` is the primary key. One row = one KODE.

### Import pipeline — never write directly to live prices

```
upload → store raw file forever → parse to staging (queued job)
       → validate → diff preview → human approves → publish as new version
```

Diff preview shows five buckets with counts: new SKU, price changed (old → new, %),
unchanged, missing from file, errors.

**Never auto-deactivate SKUs missing from an uploaded file.** Default is leave-alone +
flag. A "this file is a full replacement" checkbox is the only way to opt into deactivation.

**Safety brake:** if >20% of prices change, or any single price moves >50%, require a
second confirmation that names the numbers.

### Versioning

```
price_list_versions  → id, effective_from, published_at, published_by,
                       source_file_path, note, status
price_list_items     → version_id, kode, harga, qty_per_ctn, aktif
```

Never UPDATE a price. Insert a new version.

### Known quirks in supplier source files

The raw supplier workbook (`PL_JAVA_IMPORT.xlsx`) is messy. The tolerant importer must handle:

- Sheet names do not match brands. `MERK` column is authoritative.
- ~55 repeated header rows scattered mid-file
- Category is **not a column** — it's the nearest title-only row above
- **Two header rows are mislabeled** (row 872, 884) — the text disagrees with the data below.
  Map columns by position with validation, never by reading header text.
- 183 phantom columns on one sheet
- `KODE` cells containing 2+ SKUs separated by `/`
- `QTY/CTN` cells containing two values (`18 / 10`)
- ~724 blank `QTY/CTN` — default to 1, record note in CATATAN
- No effective date anywhere in the file

Blockers (route to review queue): split KODE, duplicate KODE, non-numeric price,
double QTY/CTN, missing MERK, undetected category.
Notes (import anyway, annotate): blank QTY/CTN, non-standard KODE format.

---

## Buyer portal priority

B2B buyers reorder the same 15–20 SKUs forever. They restock; they don't shop.
Landing screen after login, in this order:

1. **Reorder** — last order, one click to repeat, quantities editable (80% of usage)
2. **Available credit**, shown persistently
3. Catalog with their prices
4. Order history + invoice/surat jalan PDFs
5. Outstanding invoices with due dates

## Admin panel priority

Worklists, not CRUD tables. Admin home is queues:

- Orders awaiting approval (credit + stock shown inline)
- Accounts awaiting approval
- Payments received but unmatched
- Confirmed orders ready to pick
- Invoices overdue by age

Generic CRUD exists behind these for corrections only.

---

## Not in v1 — do not build

Shipping-rate API integration · Coretax API integration · mobile app · real-time
notifications · multi-currency · product reviews · recommendation engine · promo/voucher
engine · public price display.

## Build order

0. Xendit sandbox, KBLI check on NIB, price tier structure on paper
1. **Admin panel only** (~4–6 wk) — staff enter real orders, no buyer login at all
2. Xendit fixed VA + webhooks + reconciliation (~2–3 wk)
3. Buyer portal, pilot with 3–4 friendly customers (~4–6 wk)
4. Public site, privacy policy, PSE registration, launch
5. Reporting and refinement

Phase 1 must run the real business before any buyer logs in.

---

## Compliance

- **PSE Lingkup Privat** registration with Komdigi via OSS → PB-UMKU. Required before the
  system is used by users. Free.
- Site must carry a Kebijakan Privasi page (UU PDP 27/2022).
- Written terms of sale covering credit terms, late payment, returns, delivery.

## Conventions

- Migrations are additive. Never edit a shipped migration.
- Every money-affecting action writes to the audit log.
- Queue jobs must be idempotent — assume they run twice.
- Tests required for: price resolution, credit check, stock reservation, webhook handling,
  tax calculation. These five are where bugs cost money.
- Backups: nightly `pg_dump`, encrypted, off-box. Test restore before launch.
