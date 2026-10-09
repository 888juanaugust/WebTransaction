# Branches and the order flow — design

Sub-project 1 of `docs/ROADMAP.md`. Status: approved 2026-10-09.

## Goal

Central's customers buy on credit from several branches (cabang). This sub-project gives
the base's branches what the operation needs: a code that document numbers carry, a
team (sales + marketing seat) per customer, approval of a sales order by that customer's
marketing seat or the owner, a stock reservation taken at approval and consumed by the
delivery, and the split of an order whose goods sit in several warehouses.

Decisions taken with the owner:

- The team fields on customers come now (sub-project 3 keeps claims, debt and the
  collection desk).
- **A reservation never expires.** It is held until the goods are delivered, the order is
  rejected, or an edit reopens its approval.
- The split is shown at approval and the approver confirms it.

Everything lives in the client layer (`app/Client`, `config/client.php`) except the base
edits listed at the end.

## 1. Branches as cabang

- `branches` gains `code` (string 8, unique, nullable until set, stored uppercase),
  `latitude` and `longitude` (decimal 9,6, nullable). The tax identity stays `nitku`.
- The Branches screen shows and edits the three fields; `code` is required on the form.
- `App\Client\Seeders\BranchSeeder` gives the default branch the code `PST` when it has
  none.
- Who sees which branch stays the base's rule: `branch_users` and `used_all_user`,
  administrators see all, `BranchLimit` filters every list. Marketing users are given every
  branch; there is no session-wide "active branch".

## 2. The branch in document numbers

- `PatternToken` gains `Branch` (format alias `BR`), rendered as the branch code.
- `NumberPattern::render()` and `NumberGenerator::next()` / `preview()` take an optional
  branch code. When the pattern holds a branch token the counter's `period_key` is prefixed
  with the code, so each branch counts alone within its reset period; no schema change,
  `document_counters` is keyed by series and period key already. A pattern with a branch
  token and no branch code throws.
- `CreatesNumberedRecord::assignNumber` passes the code of the form's `branch_id`; the
  other callers of `next()` that number a document with a branch pass it too.
- `App\Client\Seeders\DocumentSeriesSeeder` sets the default series of sales quotations,
  orders, deliveries, invoices and returns, goods receipts, item transfers and inventory
  adjustments to `{prefix}-BR-YYMM-####`, monthly reset. Numbers read `SO-JKT-2610-0001`.

## 3. Teams on customers

- `customers` gains `sales_user_id` and `marketing_user_id` (users, nullable, null on
  delete).
- `App\Client\Domain\Teams\TeamAssigner::assign(Customer, ?User $sales, ?User $marketing,
  User $actor)`:
  - the actor is an administrator;
  - the sales is active, belongs to the Sales group and may work in the customer's branch
    (`BranchLimit::allows`);
  - the marketing is active, belongs to the Marketing group and sees every branch;
  - both seats are written together and the change is audited (`team_assigned`, before
    and after).
- Group names are constants in `App\Client\Access\CentralGroups` (Sales, Marketing,
  Inventory, Warehouse, Finance). `App\Client\Seeders\CentralGroupSeeder` adds Marketing
  (the Sales group's rights plus the special rights *approve transactions* and *see
  credit data*) and Inventory (the Warehouse group's rights plus *see cost*), and takes
  *approve transactions* away from Sales. The full rights matrix is sub-project 3.
- Screen **Customer Teams** (`client__teams`, Sales group, a work screen): customers with
  their branch and both seats, and an *Assign team* action that calls `TeamAssigner`.
  Rights seeded to Administrator only.

## 4. Approval by the marketing seat

- `App\Client\Modules\OrdersModule::boot()` registers an `ApprovalType` for `SalesOrder`
  after the base's, which replaces it. It wraps `OrderApproval::type()`:
  - `beforeApprove`: the actor is an administrator or the customer's marketing seat
    (otherwise "Only this customer's marketing or the owner approves an order"); then the
    base credit check; then the split check of §6.
  - `changed`: the base's status write, then `Reservations::sync()` — reserve when the
    request is approved, release otherwise.
- `App\Client\Seeders\PreferenceSeeder` switches *Sales Order Approval* on and sets the
  credit notice and freeze days to 120 and 150.
- The base's approve and reject actions keep working for an order whose goods sit in one
  warehouse; `beforeApprove` is the hard guard either way.

## 5. The stock reservations ledger

- `stock_reservations(id, sales_order_id, sales_order_line_id, item_id, warehouse_id,
  base_quantity decimal 18,4, status held | released | consumed, reason, posting_id,
  created_at, resolved_at)`, indexed on item, warehouse and status. Append-only: a row is
  never edited after it is resolved; a partial consume resolves the held row and opens a
  new held row for the remainder.
- `App\Client\Domain\Stock\Reservations`:
  - `reserve(SalesOrder)` runs inside the caller's transaction. Lines are taken in item,
    warehouse, id order; a group item is exploded into its components with
    `GroupItems::explode`; a line's warehouse is its own, else the customer's default, else
    the default warehouse. The `item_costs` row is locked `FOR UPDATE` (inserted first when
    missing); available = on hand − held; a shortfall throws
    `InsufficientStockException` naming the item, warehouse and quantity short; otherwise
    held rows are written. Idempotent: an order that already holds rows is left alone.
  - `release(SalesOrder, reason)`: held → released.
  - `available(item, warehouse)`, `heldSum(item, warehouse)`, `heldByOrder(order)`.
  - `sync(order, request)`: approved and nothing held → reserve; not approved and
    something held → release.
- A posting writer registered in the module's `boot()` runs inside every delivery's
  posting transaction: each outgoing stock movement whose delivery line pulls from an order
  line consumes that line's held rows (same item and warehouse) up to the movement's
  quantity; a delivery from another warehouse releases the reservation with reason
  `delivered_elsewhere`. Then, for every item and warehouse the posting touched, on hand −
  held must not be negative: goods reserved for other orders cannot leave. On unpost, the
  rows that posting consumed are reopened as new held rows.
- No expiry job. Rejection releases; so does any edit that reopens the approval.

## 6. Splitting an order across warehouses

- `App\Client\Domain\Orders\OrderSplitter::plan(SalesOrder): SplitPlan` is pure. Warehouse
  priority: the line's warehouse (or the customer's default), then the other active
  warehouses of the customer's branch by code, then the other warehouses by the total
  available of the order's items, largest first. Lines are walked in order and allocated
  greedily; a line may split. The plan holds one share list per warehouse and the
  shortfalls; `needsSplit()` is true when more than one warehouse ships.
- `OrderSplitter::execute(SalesOrder, SplitPlan, User $actor)` runs in one transaction:
  the parent keeps the first warehouse's shares (lines shrunk or removed, restated in base
  units, totals refreshed, saved through `DocumentRepository::updated`); every other
  warehouse gets a sibling order in that warehouse's branch, numbered from the series with
  that branch's code, `split_parent_id` pointing at the parent, customer, terms and PO
  copied, lines in base units with the warehouse set, created through
  `DocumentRepository::created`; then every piece is approved by the actor through the
  engine, so each one is credit-checked (cumulatively), seat-checked and reserved; the
  split is audited on the parent. Any failure rolls everything back.
- `sales_orders` gains `split_parent_id` (self, nullable).
- `beforeApprove` refuses an order whose plan needs a split unless the approval comes
  through the splitter: "Goods sit in several warehouses — approve from Order Approvals".
- Screen **Order Approvals** (`client__order-approvals`, Sales group, a work screen): the
  orders awaiting approval the user may see, with customer, number, total, available credit
  and stock coverage. *Approve* opens the plan — one block per warehouse with its lines, and
  the shortfalls — and confirms the split and the approvals, or approves plainly when one
  warehouse covers it. *Reject* takes a reason.

## Base edits

Allowed by CLAUDE.md where the client layer cannot reach, and limited to:

- `app/Domain/Numbering/PatternToken.php`, `NumberPattern.php`, `NumberGenerator.php` — the
  branch token and code.
- `app/Filament/Support/CreatesNumberedRecord.php` and the other callers of
  `NumberGenerator::next()` that number a document carrying a branch — pass the code.
- `app/Filament/Resources/Settings/DocumentSeries/DocumentSeriesResource.php` — the example
  renders with a sample code.
- `app/Filament/Resources/Company/Branches/BranchResource.php` — code, latitude, longitude.

## Tests

`tests/Feature/Client/`: `BranchNumberingTest` (per-branch counters, missing code
refused), `TeamAssignerTest` (rules and audit), `SeatApprovalTest` (own marketing, other
marketing, sales, administrator; the rule off auto-approves and reserves),
`StockReservationTest` (reserve, shortfall, idempotence, consume, partial consume,
delivered elsewhere, unpost reopens, other orders' stock protected, release on reject),
`OrderSplitTest` (two warehouses, re-home, shortfall, all-or-nothing on a credit failure),
`OrderApprovalsPageTest` (reachability, rights, the plan shown, approve and reject).
