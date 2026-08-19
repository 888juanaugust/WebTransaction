# How to demo this

A fresh install has four staff logins and empty screens — no products, no
prices, nothing to look at. That is deliberate (a seeded price is a price
nobody approved), and it also makes the system impossible to show to anybody.

`DemoSeeder` fills that gap: a believable day in the life of the business, with
every dashboard queue populated and a real variance waiting to be found. Thirteen
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

## 3. The thirteen-minute script

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

### Sending a delivery back — 2 min

**Pembelian → Retur pembelian → Buat retur.** Pick the posted receipt, give a
reason, and the draft arrives with every line on it at full quantity. Cut one
down to 30 and delete nothing else.

> "It starts from everything on purpose. Forgetting to delete a line means you
> returned too much, and the supplier rings you. Forgetting to add one means
> you quietly kept goods you are still paying for, and nobody rings anybody."

Post it, and look at **Hasil** at the bottom of the screen. Three figures, and
the middle one is the point:

- **Dikreditkan pemasok** — what the supplier now owes us, PPN and all.
- **Akrual dibatalkan** — the part they had not billed yet, which unwinds the
  receipt instead of reducing a debt.
- **Selisih harga** — the goods left the shelf at the moving average, which has
  drifted since they arrived. That difference is a real gain or loss.

> "Whether the supplier had invoiced the delivery yet decides which account
> this touches. Both happen on the same delivery, and getting it wrong balances
> perfectly — which is exactly why the system works it out rather than asking."

**Cetak nota retur** produces the document that goes back on the truck. Under
the PPN rules the *buyer* issues it, so it carries our number and our NPWP, and
the supplier's credit note comes back against it. Until it does, the return
shows a badge in the sidebar — that gap is money nobody else is watching.

### The cheque in the drawer — 2 min

**Bilyet giro.** This is the one an Indonesian wholesaler will ask about first.

> "A customer settles a forty-million invoice by handing over a piece of paper
> dated sixty days out. What does your system do with that?"

Open **Bilyet giro**. Three of them, sorted by the date printed on the paper —
the top one is amber and says *sudah bisa disetor*, because it came due and
nobody has banked it.

Open the first giro and read the box at the bottom:

> "Twenty million moved from Piutang Usaha to Piutang Giro. No money has come
> in, the invoice is still open, and **the customer's credit limit has not come
> back** — because a giro can bounce."

That last part is the whole feature. Show it on **Buku besar → Neraca saldo**:
Piutang Giro sits on the balance sheet as its own line, and the control check
beside it ties to the register.

> "If we called it a payment, the books would claim money that is not in the
> bank, and the customer could order again on the strength of a promise. That
> is exactly how somebody rolls one bounced cheque into the next order."

Then **Cair** on one, and **Tolak** on another with "saldo tidak cukup".
Clearing records a real payment and settles the invoice; the bounce puts the
debt straight back and there is nothing to unwind — because nothing was ever
paid.

Giro we write to suppliers work the same way in reverse: **Terbitkan giro**
moves what we owe into Utang Giro, so next month's cash figure knows the money
is already committed to a date.

### Proving the bank — 2 min

**Buku besar → Rekonsiliasi bank.** The one control in the whole system whose
other side is not our own arithmetic.

> "Every other check here proves our records agree with each other. Piutang
> Usaha against our invoices, Persediaan against our costing. Not one of them
> can prove the money is actually in the bank. Only the statement can."

A reconciliation is already open against yesterday's statement, and it does not
balance — **Selisih Rp 17.500**, with the yellow panel naming the likely cause:
a bank charge nobody entered. Note two things before fixing it:

- **Setoran dalam perjalanan Rp 4.250.000** — a transfer recorded after the
  statement was printed. It is not a discrepancy, it is timing, and it moves
  the difference by nothing at all.
- **Selesaikan is greyed out.** It stays that way until the difference is nil.

> "The temptation in every accounting package is to let somebody sign off with
> a small unexplained figure. Three months later it is nine hundred thousand
> and nobody knows when it started."

Click **Tambah item rekening koran** → *Biaya administrasi Agustus*, uang
keluar, 17.500, against Beban Operasional. The journal posts on the spot — the
bank has already taken the money — the line ticks itself, and the difference
falls to nil. **Selesaikan** comes alive; sign it off and the figures freeze
onto the record.

Then **Neraca saldo**: Bank ties to the balance that was just proved, and the
Rp 17.500 is sitting in Beban Operasional with a name on it.

### Where the money actually went — 1 min

**Buku besar → Laba rugi.** Scroll to Beban Operasional.

> "Most systems this size have one line here that says 'expenses'. That is not
> an answer to anything."

Gaji, sewa, listrik, kendaraan, ongkos kirim, perlengkapan — each its own line,
each traceable to a document under **Beban**. Note the net profit against the
gross: overheads take roughly Rp 31,6 juta out of Rp 51,3 juta of gross profit.

> "Without those entries the system would have reported the gross figure as
> profit, and that is the number tax gets calculated on."

Two of them came out of the cash box rather than the bank — BBM and the packing
materials — which is why Kas has a balance on the neraca. Recording those
against the bank instead is how a bank reconciliation stops balancing.

### Close on the numbers — 1 min

> "Because every stock movement carries what it cost, the system can say what
> the inventory is worth and what each sale actually made."

Inventory is around Rp 192 million; roughly Rp 13 million of goods have been
sold at cost so far. On the catalogue screen, finance and the owner can switch
on **HPP rata-rata** — the moving average cost per part. Sales cannot see it,
because cost plus selling price is margin.

### The four reports — 2 min

Open **Laporan**. Four questions, one screen each, every one downloadable as a
spreadsheet.

**Penjualan** — who bought and what it made. Regroup it by merk or category
from the same screen. The total agrees with the Penjualan account in the
ledger, and margin uses the cost frozen when the goods left rather than
today's average.

**Umur piutang** — what is owed, in 30/60/90 buckets. The total must equal
Piutang Usaha in the neraca; if it ever does not, the report says so above the
table rather than leaving somebody to find it.

**Pelanggan pasif** — customers who have stopped ordering, measured against
each customer's own rhythm rather than one threshold for everybody. A
distributor ordering weekly and a bengkel ordering quarterly are both healthy;
this catches the one that broke its habit.

> "Nothing else in the system watches for this. A customer who stops just
> stops — no queue empties, nobody complains."

**Perputaran stok** — what is on the shelf and not moving, ranked by the money
tied up in it, with months of cover beside each part. Nine parts here have
never sold at all, and they are at the top of the list because that is where
the cash is stuck. Finance and the owner only: it is a cost report.

---

## 4. Questions you will be asked

**"Can it do accounting?"** Yes, now — see section 3 below. Every document
posts double entry as it happens, and **Buku besar** carries neraca, laba rugi,
neraca saldo, the journal and period close. Closing a month locks it: a
document back-dated into it is refused, with the document, rather than quietly
restating figures already reported. Closing December closes the year into Laba
Ditahan. Returns are recorded as credit notes, which put stock back at the cost
it left at and reverse the sale in the books. Bilyet giro are a first-class
document rather than a payment: a customer's cheque sits in Piutang Giro until
it clears, and one we write sits in Utang Giro, so the balance sheet says what
is backed by paper and what is not. Stock moves between warehouses on
a transfer document, and the shelf is counted on an opname sheet that Warehouse
fills in and Finance approves — never the same person. Goods sent back to a
supplier come off the shelf on a retur pembelian, which reduces what we owe them
where they had already invoiced it and unwinds the accrual where they had not. Freight and duty are
spread over the goods they belong to, with the share for stock already sold
going to HPP rather than restating shipments that have gone. **Buku besar →
Faktur pajak** exports a month's output VAT for filing and records the nomor
seri that come back — read the note on that screen about the file format
before the first real filing. **Laporan** carries the four reports the numbers
are actually read through — sales and margin, receivables ageing, customers who
have stopped ordering, and stock that is not moving — each downloadable as a
spreadsheet and each tied back to the ledger account it must agree with. And
**Buku besar → Rekonsiliasi bank** ticks the Bank account off against a real
statement — the only check in the system whose other side did not come from us,
and the one that finds the charge nobody entered or the payment recorded twice.

Two things to raise with your accountant rather than take on trust: orders are
invoiced when they move to awaiting payment, which is **before** they ship, so
revenue is recognised ahead of its cost; and PPN on a supplier bill with no
faktur pajak is booked to expense rather than into stock value. Both are
written up in `docs/MAP.md`.

**"Is it finished?"** The order-to-cash and purchase-to-pay chains are complete
and tested, and so are the books over the top of them — 1231 tests. Before real users touch it: PSE registration, a
lawyer's review of the two legal pages, and the real company details replacing
the placeholders.

**"Why not Odoo?"** A fair question, and there is a written evaluation kit for
exactly that in `docs/odoo-evaluation/` — including a sample of the real
supplier workbook to throw at it. The comparison has moved since it was
written: this now has a general ledger, so the gap is narrower than the
"tuned to your trade but no accounting" summary suggests. What Odoo still has
and this does not is depth — multi-currency and fixed assets. Bank
reconciliation is no longer on that list.

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
