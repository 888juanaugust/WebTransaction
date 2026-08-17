# How to demo this

A fresh install has four staff logins and empty screens — no products, no
prices, nothing to look at. That is deliberate (a seeded price is a price
nobody approved), and it also makes the system impossible to show to anybody.

`DemoSeeder` fills that gap: a believable day in the life of the business, with
every dashboard queue populated and a real variance waiting to be found. Ten
minutes, start to finish.

---

## 1. Get it running

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
```

PostgreSQL and Redis both need to be running — **Redis is on the login path**,
and the panel returns a 500 without it.

```bash
php artisan migrate:fresh --seed        # staff logins, warehouse, price tiers
php artisan db:seed --class=DemoSeeder  # the demo business
php artisan serve
```

Then open **http://127.0.0.1:8000/admin**.

On Windows use PowerShell and run the same commands. If `composer` or `php` is
not recognised, they are not on your PATH — install PHP 8.4 and Composer, then
reopen the terminal. `php artisan serve` must keep running in its own window
while you demo; open a second window for anything else.

**To reset between demos**, run the two seed commands again. `DemoSeeder`
refuses to run on a database that already has orders in it, so the
`migrate:fresh` is not optional.

It also refuses to run when `APP_ENV=production`. It writes invented prices
into append-only ledgers, and there is no clean way back out.

## 2. Logins

Every password is `password`.

| Who | Where | Email |
|---|---|---|
| Pemilik (sees everything) | `/admin` | `owner@example.test` |
| Sales | `/admin` | `sales@example.test` |
| Keuangan | `/admin` | `finance@example.test` |
| Gudang | `/admin` | `warehouse@example.test` |
| **Buyer — CV Sinar Distribusi** | `/portal` | `distributor@pembeli.example` |
| Buyer — Toko Sparepart Makmur | `/portal` | `toko@pembeli.example` |

Use the **distributor** login for the portal. They have unpaid invoices, so the
Virtual Account and the credit figures have something to show. The toko buyer
has paid everything and their invoice screen is empty.

---

## 3. The ten-minute script

Tell it as a day in the business, not as a tour of features. The order below
builds on itself.

### Open on the dashboard (owner) — 1 min

> "This is what staff see when they log in. Not a menu — a to-do list."

Five queues, all with something in them: an order waiting for approval, a new
customer account waiting to be approved, money in that nobody has matched to an
invoice yet, an order the warehouse can pack today, and an invoice 22 days
overdue.

**The point:** the software says what needs doing, rather than asking you to
remember.

### Approve an order — 2 min

On **Order menunggu persetujuan**, look at the row before clicking: it shows
the customer's remaining credit and whether stock is sufficient, inline.

Click **Setujui**.

> "Three things just happened in one transaction: the price was locked, the
> credit limit was checked, and the stock was reserved. If any of them had
> failed, none of them would have happened."

Open the order and show the event log — who did what, when, with a reason.

### Show the money is locked — 1 min

Open the order's lines. Every line carries its own price, discount, DPP and PPN
as they stood at the moment of approval.

> "The price list can change tomorrow. This order won't."

Then **Faktur → Cetak faktur** on an invoiced order. Point at the per-line DPP
and PPN, and the terbilang line.

> "PPN is 12%, but for ordinary goods the base is 11/12 of the price, so the
> real burden is 11%. That is computed per line, because summing rounded lines
> is not the same number as rounding a summed total — and the difference ends
> up on a tax return."

### Switch to the warehouse login — 1 min

Log in as `warehouse@example.test`.

> "Same system, different person."

The catalogue has no prices. There is no customer screen, no invoice screen, no
purchasing. **Pengiriman** shows what to pack. Print a **surat jalan**: goods,
quantities, signature blocks, and not one rupiah anywhere.

> "It gets handed to a driver and then to whoever signs for it. Neither of them
> is party to what this customer pays."

### Switch to the buyer portal — 2 min

Open `/portal` as `distributor@pembeli.example`.

> "This is what the customer sees."

Their remaining credit at the top. Last orders with **Pesan ulang** beside each
one — click it, and last month's quantities come up filled in and editable.

> "A workshop reorders the same fifteen parts forever. They restock; they don't
> shop. So the first thing on the screen is last order, one click."

Then **Tagihan** → open one → the fixed Virtual Account to pay into, and
**Cetak faktur** so they can send it to their own accountant.

### The buying side — 2 min

Back as `finance@example.test`, go to **Pembelian → Pesanan pembelian**.

Open the ⋮ menu on the sent PO and choose **Cocokkan**.

> "This is the control that pays for itself."

Two rows flagged in red: one where the supplier billed 5% more than the goods
were received at, and one still short by two cartons.

> "Ordered, received, billed — three documents that have to agree. A short
> delivery billed in full, or a price that quietly moved between quote and
> invoice, is money out of the door and nobody notices without this."

Also from that menu: **Cetak PO** — the document the supplier receives, which
asks them to quote our PO number on their surat jalan and faktur. That
reference is what makes the matching possible in the first place.

### Close on the numbers — 1 min

> "Because every stock movement carries what it cost, the system can say what
> the inventory is worth and what each sale actually made."

Inventory is around Rp 192 million; roughly Rp 13 million of goods have been
sold at cost so far. On the catalogue screen, finance and the owner can switch
on **HPP rata-rata** — the moving average cost per part. Sales cannot see it,
because cost plus selling price is margin.

---

## 4. Questions you will be asked

**"Can it do accounting?"** Yes, now — see section 3 below. Every document
posts double entry as it happens, and **Buku besar** carries neraca, laba rugi,
neraca saldo, the journal and period close. Closing a month locks it: a
document back-dated into it is refused, with the document, rather than quietly
restating figures already reported. Closing December closes the year into Laba
Ditahan. Returns are recorded as credit notes, which put stock back at the cost
it left at and reverse the sale in the books. Stock moves between warehouses on
a transfer document, and the shelf is counted on an opname sheet that Warehouse
fills in and Finance approves — never the same person. What is still missing is
landed cost. That is in `docs/MAP.md`.

Two things to raise with your accountant rather than take on trust: orders are
invoiced when they move to awaiting payment, which is **before** they ship, so
revenue is recognised ahead of its cost; and PPN on a supplier bill with no
faktur pajak is booked to expense rather than into stock value. Both are
written up in `docs/MAP.md`.

**"Is it finished?"** The order-to-cash and purchase-to-pay chains are complete
and tested, and so are the books over the top of them — 811 tests. Before real users touch it: PSE registration, a
lawyer's review of the two legal pages, and the real company details replacing
the placeholders.

**"Why not Odoo?"** A fair question, and there is a written evaluation kit for
exactly that in `docs/odoo-evaluation/` — including a sample of the real
supplier workbook to throw at it. The comparison has moved since it was
written: this now has a general ledger, so the gap is narrower than the
"tuned to your trade but no accounting" summary suggests. What Odoo still has
and this does not is depth — multi-currency, fixed assets, bank
reconciliation.

**"Can I break it?"** Encourage it. Try to approve an order that exceeds the
credit limit, or ship stock that isn't there. Refusals are the feature.

---

## 5. What the demo data contains

| | |
|---|---|
| Products | 12, across all four categories and seven brands |
| Price list | One published version, plus a distributor quantity break on YH-1001 at 60+ pieces |
| Customers | 3 active on three different tiers, 1 awaiting approval |
| Opening stock | Entered as a goods receipt at ~70% of selling price, so margin is real |
| Orders | One in each state: submitted, confirmed, awaiting payment, paid, completed, plus one overdue |
| Purchasing | A PO, a short delivery, and a bill 5% over — so the match has both variances |
| Payments | One transfer with no invoice attached, for the reconciliation queue |

Every price in it is invented. Nothing here came from a supplier.
