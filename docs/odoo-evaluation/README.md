# Evaluating Odoo 19 against this business

An honest test of whether Odoo 19 should replace what is in this repository.
It is written to be run by somebody who has not used Odoo before, and to reach
a decision on evidence rather than on impressions.

**This directory is not part of the application.** Nothing here is loaded by
Laravel, nothing here runs in CI. Delete it once the decision is made.

## Why you are running this yourself

I could not run it for you. The sandbox this project is developed in has Docker
available, and the daemon starts, but the egress policy allows Docker's registry
API (`registry-1.docker.io`) while blocking the CDN that serves the actual image
layers (`production.cloudfront.docker.com`, 403 at the proxy). No image can be
pulled, so no container can start. That is an environment policy, not an Odoo
problem — on your own laptop or VPS it will just work.

## Running it

```bash
cd docs/odoo-evaluation
mkdir -p addons
docker compose up -d
# first boot takes a minute or two while it initialises
open http://localhost:8069
```

Create the database exactly as the comments in `docker-compose.yml` say. Two
settings matter more than they look:

- **Country: Indonesia.** This loads the `l10n_id` localisation — Indonesian
  chart of accounts and tax configuration. Without it you are evaluating a
  generic ERP, not the one you would actually run.
- **Demo data: unchecked.** Otherwise you cannot tell Odoo's invented products
  from yours.

Then install these apps from the Apps menu: **Inventory**, **Purchase**,
**Sales**, **Invoicing**, **Accounting** if your edition has it.

## The five things worth testing

Anyone can make Odoo look good with a clean spreadsheet of ten products. These
are the five places where this specific business is awkward, and where the
answer actually decides something.

### 1. The supplier price list

`contoh-price-list.xlsx` in this directory is a synthetic file reproducing every
quirk of the real `PL_JAVA_IMPORT.xlsx` — same column order, same problems, no
real prices. Seventeen rows standing in for 1,457.

The quirks it contains, all of which the importer in this repo already handles:

| Row | Quirk |
|---|---|
| 2, 8, 13 | Category is a **title-only row**, not a column |
| 6 | A **repeated header row** in the middle of the data |
| 9 | `QTY/CTN` of `18 / 10` — **two values in one cell** |
| 10 | **Blank** `QTY/CTN` |
| 11 | `26-22-55` — a **dimension string**, not a pack size |
| 12 | `SX-6006 / SX-6007` — **two SKUs in one KODE cell** |
| 15 | **Duplicate KODE** at a different price |
| 16 | `TBA` — **non-numeric price** |
| 17 | **Missing MERK** |

Try to import it through **Inventory → Products → Import**. Judge honestly:

- Does it get the category from the title rows, or does it need a category column?
- What does it do with `18 / 10` and `26-22-55`? Silently wrong is much worse
  than refused — this repo's importer once read `26-22-55` as 262,255 units per
  carton and published it.
- Does the duplicate KODE overwrite, duplicate, or stop?
- Does `TBA` land as zero?

**The question to answer:** how much Python would you write to make the routine
"export → edit HARGA → re-import" cycle safe? That cycle is the single most
frequent operation this business does with the system.

### 2. PPN under PMK 131/2024

For ordinary non-luxury goods the DPP is **11/12 of the selling price** and PPN
is 12% of that, so the effective burden is 11%. In Coretax this is transaction
code **04**, not 01.

In Odoo: **Accounting → Configuration → Taxes.** Find or create the 11%/12%
arrangement and check it against a real line. Sell 10 pieces at Rp 500,000:

| | Expected |
|---|---|
| Harga jual | Rp 5,000,000 |
| DPP | Rp 4,583,333 |
| PPN | Rp 550,000 |
| Total | Rp 5,550,000 |

Then check the invoice **prints DPP and PPN per line**, not only on the total.
Summing rounded lines is not the same number as rounding a summed total, and
the difference lands on a faktur pajak.

### 3. Coretax e-Faktur

Odoo 19's Indonesian localisation reportedly exports Coretax-compliant **XML**
(Actions → Download e-faktur, per invoice or batched).

**Verify the format with your accountant**, and note this affects the decision
either way: `CLAUDE.md` in this repo specifies a **CSV** export, and if Coretax
now wants XML then that spec is out of date and the export we have not built
yet would have been built wrong. This is worth five minutes of their time
regardless of which system you choose.

Also check what happens when an invoice is corrected after the XML is
downloaded. Odoo's documentation notes the file cannot be edited and only the
first version is valid.

### 4. Cartons and pieces

Every SKU here has a base unit (PCS or SET) and a `qty_per_ctn`. Suppliers
invoice in cartons; stock is counted in pieces; order lines must store **both**.

Set up a product with 12 pieces per carton. Then:

- Buy 10 cartons at Rp 120,000 per carton. Stock should read **120 pieces** at
  **Rp 10,000** each.
- Sell 5 pieces. Check the COGS is 5 × Rp 10,000.
- Buy 10 more cartons at Rp 132,000. Check the moving average moves correctly
  and that **the earlier sale's COGS did not change**.

That last point is the one to be strict about. If buying stock today rewrites
last month's margin, no monthly report can be trusted.

### 5. The buyer portal

B2B buyers here restock the same 15–20 SKUs forever. The portal in this repo
opens on **reorder** — last order, one click, quantities editable — because
that is roughly 80% of what a buyer does.

Odoo has a customer portal and an eCommerce app. Log in as a portal user and
ask: how many clicks to repeat last month's order? Does the buyer see **their**
negotiated prices, or list prices? Can they see their credit limit?

## What to write down

For each of the five, one line: **works out of the box / needs configuration /
needs a custom Python module / cannot do it.** That table is the decision.

Add the two costs that are not features:

- **Odoo Enterprise** is roughly €20–24 per user per month, and full accounting
  — bank reconciliation, automated matching, vendor bill OCR — is Enterprise
  only. Community has invoicing, not the complete accounting module.
- **Upgrade churn.** Odoo releases annually. Odoo 19 is from September 2025;
  20 is due around September 2026. Custom modules are re-tested every year.

## What this repository already does, for comparison

Not to argue for it — so the comparison is like for like.

| | Status |
|---|---|
| Price list import with all the quirks above | Built, 1,457 rows → 1,402 published, 55 routed to review |
| PPN 11/12 per line, code 04 | Built and tested |
| Coretax export | **Not built** (and specified as CSV, which may be wrong) |
| Cartons/pieces, moving average, frozen COGS | Built and tested |
| Buyer portal with reorder | Built |
| Order to cash, purchase to pay, three-way match | Built |
| Surat jalan, faktur, purchase order documents | Built |
| General ledger, neraca, laba rugi, trial balance | Built and tested |
| Control accounts reconciled against their subledgers | Built and tested |
| Period close: months locked against back-dating, year-end into Laba Ditahan | Built and tested |
| Credit notes: returns and price corrections, with the segregation control | Built and tested |
| Warehouse transfers, and stock opname with counter/approver separated | Built and tested |
| Landed cost, split between stock on hand and stock already sold | Built and tested |
| Multi-currency, fixed assets, bank reconciliation | **Not built**, and not planned |

The summary this file was written with — "tuned to your trade, no accounting
core" — is now out of date: the ledger exists, every document posts to it, and
the four control accounts tie to their subledgers. What Odoo still has and this
does not is depth rather than presence: bank reconciliation, fixed assets,
multi-currency. Weigh that against how much Python the five tests
above turn out to need.

## One timing note

Switching is much cheaper now than after go-live with real customers, real
stock history and real invoices. If Odoo is going to be considered seriously,
this is the moment — which is an argument for taking the suggestion seriously,
not against it.
