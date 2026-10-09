# Teams, claims and debt — design

Sub-project 3 of `docs/ROADMAP.md`. Status: approved 2026-10-11; built 2026-10-11.

## Goal

Central's money and stock move on two keys: a sales or marketing seat files a claim,
somebody else verifies it, and the base document that moves the money or the stock is
made in the verifier's name. The previous system had three such claims (pelunasan
piutang, sales expense, return), a nightly aging sweep (a notice at 120 days, a freeze
after 150), a collection desk (contacts, promises, a worklist) and one warehouse account
per warehouse. The base has receipts, cash payments, sales returns, the credit check with
notice and freeze days, warehouse users, branch limits, the approval engine and queued
mail; it has no claims, no notice, no collections, no warehouse binding and no fulfilment
queue. Teams came in sub-project 1; this sub-project completes the rights of the six roles.

Decisions taken with the owner:

- A return is a **return claim** verified by Inventory; the verification creates the base
  Sales Return, so stock moves only then.
- The 120-day notice is **an email to the customer and the team, logged once per
  invoice**. No in-panel bell.
- Gudang gets both the **binding** (one active account per warehouse) and the
  **fulfilment queue** with a Deliver action.
- An expense claim names an **optional customer** of the filer; Finance picks the expense
  account and the paying account when verifying.

## 1. The rights matrix

One seeder, `App\Client\Seeders\CentralGroupSeeder`, shapes the six groups from the role
table in CLAUDE.md. A group that already holds rights is left as the Owner shaped it on the
Access Groups screen. Rights sets: ALL (view, create, update, delete, print), WORK (view,
create, update, print), FILE (view, create, print: filing a claim; the Update right is the
verifier's key), READ (view, print).

| Group | ALL | WORK | READ | Special |
|---|---|---|---|---|
| Administrator | every base and Central screen | | | every special right |
| Sales | | Sales Quotations, Sales Orders, Check-ins, Customers, Collections; FILE on Settlement Claims, Expense Claims, Return Claims | Delivery Orders, Sales Invoices, Sales Receipts, Sales Returns, Items, Stock by Warehouse, Order Fulfilment, Price Categories, Sales Targets, Salesman Commissions, Price List, Customer Prices, Calendar, Contacts | See credit data |
| Marketing | | Sales' WORK plus Order Approvals; Sales Orders with delete (erasing drafts); FILE on Settlement Claims | Sales' READ | See credit data, Approve transactions |
| Inventory | the Inventory group's screens, Price List, Customer Prices, Fulfilment | Delivery Orders, Goods Receipts, Sales Returns, Return Claims | Sales Orders, Purchase Orders | See cost, Approve transactions (stock counts and transfers) |
| Warehouse | | Fulfilment, Delivery Orders | Stock by Warehouse | none |
| Finance | the Cash & Bank, General Ledger, Tax and Reports groups' screens, Sales Receipts, Sales Invoices, Sales Down Payments, Invoice Exchanges, Purchase Invoices, Purchase Payments, Purchase Down Payments, Payment Orders, Expense Accruals, Sales Targets, Salesman Commissions, Settlement Claims, Expense Claims, Collections | Customers | Vendors, Sales Orders, Purchase Orders, Delivery Orders, Goods Receipts, Sales Returns, Calendar, Contacts, Customer Teams | See credit data, Override credit limit, Export data |

Finance holds no Approve transactions right (orders are approved by the marketing seat or
the Owner) and nobody but Administrator may change a selling price. Marketing sees every
branch, as `TeamAssigner` already requires. A Warehouse user sees nothing outside its
screens: no cost, no credit, no catalogue, no orders.

## 2. Claims

Module `central-claims` (`App\Client\Modules\ClaimsModule`). Three claim tables share one
status set, `filed | verified | rejected`, and one guard, `App\Client\Domain\Claims\TwoKeys`:
the verifier holds the Update right on the claim's screen, the claim is still filed, and
**the verifier is never the filer, the Owner included**. A rejection needs a note. Filing,
verifying and rejecting are audited (`claim_filed`, `claim_verified`, `claim_rejected`).
Claims carry no document number; they are known by their id and by the document they
produce. Every claim records `branch_id`, `filed_by`, `decided_by`, `decided_at` and
`decision_note`. Lists are branch-limited; Sales and Marketing see only the claims of
their own customers, and a sales user only their own expense claims.

### 2a. Settlement claims (pelunasan piutang)

Screen **Settlement Claims** (`client__settlement-claims`, Sales group, work). Table
`settlement_claims(customer_id, sales_invoice_id, amount, account, sales_receipt_id,
status, …)`; `account` is where, when and how the money was handed over. One filed claim
per invoice (partial unique index).

- **File**: the filer is an administrator or the customer's sales or marketing seat; the
  invoice is approved with a balance above zero; `1 ≤ amount ≤ balance`; the account is
  not blank.
- **Verify** (Finance): two keys; the balance is checked again and a claim above the
  balance is refused (the seat re-files); a `SalesReceipt` is created in the verifier's
  name — number from the default Cash & Bank Voucher series, which now carries the branch code like
  the sales and stock documents, the cash or
  bank account and the date chosen in the modal (default: the company's default bank
  account, today), payment method cash for a cash account else bank transfer, one line
  settling the invoice for the amount — through `DocumentRepository::created`, which
  applies the period lock, posts, allocates and sets `paid` by settlement. The claim is
  then verified with the receipt's id.

### 2b. Expense claims

Screen **Expense Claims** (`client__expense-claims`, Cash & Bank group, work). Table
`expense_claims(sales_user_id, customer_id nullable, trans_date, amount, description,
cash_payment_id, status, …)`.

- **File**: the filer is a member of the Sales group; the customer, when given, is one of
  the filer's own; the amount is above zero; the date is not in the future; the
  description is not blank.
- **Verify** (Finance): two keys; a `CashPayment` is created in the verifier's name —
  number from the default Cash & Bank Voucher series with the branch code, payee the sales
  user's name, one line on the expense account chosen in the modal (accounts of the
  expense type, default `client.claims.expense_account`, Freight Out), paid from the cash
  or bank account chosen in the modal, memo from the description and the customer —
  through `DocumentRepository::created`. The base registers cash payments without an
  approval rule, so the payment is approved on save unless the company names transaction
  approvers.

### 2c. Return claims

Screen **Return Claims** (`client__return-claims`, Sales group, work). Tables
`return_claims(customer_id, sales_invoice_id, warehouse_id, reason, sales_return_id,
status, …)` and `return_claim_lines(return_claim_id, sales_invoice_line_id, item_id,
unit_id, quantity, base_quantity)`.

- **File**: the filer is an administrator or the customer's sales seat; the invoice is
  approved; a warehouse is named (default the customer's default warehouse); at least one
  line; each line's quantity is at most the invoice line's quantity less what earlier Sales
  Returns took from that line and less other filed claims on it; the reason is not blank.
- **Verify** (Inventory): two keys; a base `SalesReturn` is created in the verifier's name
  — return type invoice, source the invoice, lines copied from the invoice lines with their
  prices, discounts and tax codes, `source_line_type sales_invoice_line`, the claim's
  warehouse, the date chosen in the modal — through `DocumentRepository::created`: stock
  in at the cost the goods left with, the AR credit, the approval the base gives a return
  without a rule. A rejection leaves stock untouched.

Config `app/Client/config/claims.php` holds `expense_account` (`6300`).

## 3. Debt notices

Module `central-collections` (`App\Client\Modules\CollectionsModule`).

- `debt_notices(sales_invoice_id unique, customer_id, days, sent_to jsonb, sent_at)`.
- `App\Client\Domain\Debt\DebtNotices::due()`: approved sales invoices not paid whose
  issue date is at least the notice days ago (`CreditCheck::noticeDays()`, 0 = off) and
  that have no notice row. `send(invoice)` inserts the row first (insert or ignore, so a
  second run is a no-op), then mails `DebtNoticeMessage` in the company's language to the
  customer's email and to the active sales and marketing seats, recording the addresses;
  with no address at all the row still stands and a log line says so.
- The mail (text view `client.mail.debt-notice`): the company's letterhead, the invoice
  number and date, the balance, the age in days, and the date the account freezes
  (issue date + freeze days + 1), or that it is frozen already.
- Command `central:debt-notices`, scheduled daily at 00:30 without overlapping, on one
  server, through the module's `schedule()`; each invoice is a queued `SendDebtNotice` job.
- The freeze is the base's `CreditCheck::assert` at order approval: strictly after the
  freeze days, lifted the moment the aged invoice is paid. Nothing is stored. The portal's
  checkout gate is sub-project 4.

## 4. The collection desk

- `collection_contacts(sales_invoice_id, customer_id, user_id, branch_id, method
  phone | whatsapp | visit | email, outcome promise | asks_time | unreachable | dispute |
  paid, promise_date, promise_amount, note, contacted_at)`.
- `App\Client\Domain\Debt\CollectionDesk`: `record(invoice, actor, data)` — the invoice is
  unpaid; the actor holds the See credit data right and the Update right on Collections; a
  Sales or Marketing user only for their own customers; a promise needs a date not in the
  past and, when given, an amount above zero; any other outcome blanks the promise.
  `promise(invoice)` is the latest promise contact; `promiseKept(invoice)` is true when
  the invoice is paid or receipts dated after the contact settle at least the promised
  amount (the balance when none was given), false when the date has passed without that,
  null while the date is ahead. `chaseable(user)`: unpaid approved invoices, branch-limited,
  own customers for Sales and Marketing. `worklist(user)` buckets: promises due today,
  promises missed, overdue (past the due date) without a promise, the rest.
- Screen **Collections** (`client__collections`, Sales group, work): the chaseable
  invoices with customer, number, date, due date, age, balance, an aging badge (fine,
  notice sent, frozen), last contact and promise (date, amount, kept or not); tabs per
  bucket; actions Record contact, History, File a settlement claim.

## 5. Warehouse accounts and fulfilment

Module `central-warehouse` (`App\Client\Modules\WarehouseModule`).

- A binding is the one `warehouse_users` row of a member of the Warehouse group.
  `App\Client\Domain\Warehouse\WarehouseBinder::bind(warehouse, user, actor)`: the actor is
  an administrator; the user is active and a member of the Warehouse group; no other active
  member of that group is bound to the warehouse ("one warehouse, one account; deactivate
  the old one first"); the user's warehouse rows are replaced by this one and the user's
  branches set to the warehouse's branch; audited as `warehouse_bound`. `unbind(user,
  actor)` removes the row. `WarehouseScope::of(user)` is the bound warehouse of a
  Warehouse-group member and null for everybody else. Reactivating a bound account while
  another active account holds its warehouse is refused by a `User` observer.
- Screen **Warehouse Accounts** (`client__warehouse-accounts`, Inventory group, setup):
  the warehouses with branch and bound account; actions Bind (an active member of the
  Warehouse group) and Unbind. Administrators only.
- Screen **Fulfilment** (`client__fulfilment`, Inventory group, work): approved sales
  orders holding reservations in the warehouse — the bound warehouse for a Warehouse user,
  a warehouse filter over the warehouses they may use for everybody else. Columns: order,
  customer, date, branch, held lines and quantities. Actions: Pick list (the held lines
  with item, part number, unit and quantity) and Deliver (one row per held line, quantity
  at most the held quantity, default all of it): `DeliveryMaker::make(order, warehouse,
  quantities, actor)` creates the base `Delivery` — number from the Delivery Order series
  with the branch code, lines pulled from the order lines, the warehouse — through
  `DocumentRepository::created`, so the reservations ledger consumes the held rows; the
  delivery then opens, and its print is the surat jalan.

## Base edits

None. Claims and contacts are not numbered documents, nothing new prints, and no new
preference is needed: the notice and freeze days are the base's credit preferences and the
expense account is client configuration.

## Tests

`tests/Feature/Client/`: `RightsMatrixTest` (the six groups and the decisive cells),
`SettlementClaimTest` (who files, the balance bounds, one filed claim per invoice, the
verifier is never the filer, the receipt and the paid invoice, the shrunk balance),
`ExpenseClaimTest` (Sales only, own customer, the cash payment on the expense account),
`ReturnClaimTest` (the quantity cap, the Sales Return and the stock, rejection moves
nothing), `DebtNoticeTest` (due selection, one mail per invoice, addresses, idempotent
run), `CollectionDeskTest` (recording rules, promise kept or missed, the buckets, own
customers), `WarehouseBindingTest` (one account per warehouse, reactivation refused,
scope), `FulfilmentTest` (the queue is scoped, Deliver consumes the reservations),
`ClaimScreensTest` (the screens open for the right groups and the actions decide).
