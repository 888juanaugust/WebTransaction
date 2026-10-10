# Sub-project 7 — Roles, customer types and the acceptance clock

Status: built in steps; see the roadmap row.

## Why

The owner asked for seven roles with Indonesian names, a customer type that carries its
own promo and its own overdue duration, and an aging clock that starts when an order is
accepted rather than when the invoice is written. The base already has access groups, a
price tier per customer, payment terms, the credit check with notice and freeze days,
and two addresses per customer; this sub-project configures those and adds the three
things that do not exist: the acceptance date on invoices, a customer type master, and
the roles reshaped as asked.

## Decisions

- **Roles.** Seven: Administrator (Admin), Finance (Keuangan), Purchasing (Pembelian:
  stock, catalogue, price list and the purchasing chain), Warehouse (Gudang), Marketing
  (Penjualan), Sales, and the portal buyer (Customer). The Central group formerly called
  Inventory becomes Purchasing: the base's Purchasing row is reshaped and an installed
  company's Inventory group is merged into it. Purchase invoices and vendor payments stay
  with Finance. Accounting stays as the base seeds it (not a Central role). The Portal
  group is the portal's internal identity.
- **Role keys.** Code matches a group by `access_groups.role_key` (a Central column), not
  by its name, so the owner may rename a group on the Access Groups screen (to Keuangan,
  Pembelian, Gudang, Penjualan) without breaking the team rules. A reserved group cannot
  be deleted.
- **Installed companies.** `central:reshape-groups` re-applies the matrix to the seven
  groups (the seeder leaves a shaped group alone), audited with the diff.
- **Acceptance.** An invoice's clock starts on the order's approval
  (`sales_orders.approved_at`), stored as `sales_invoices.accepted_at` when the invoice is
  made (from its order, or its delivery's order); a direct invoice takes its own date.
  The due date is the payment term counted from the acceptance. The notice (120), the
  freeze (strictly after 150), the AR aging report, the collection desk, the portal and
  the prints all count from it. An invoice never accepted (no order behind it) counts
  from its date, as today.
- **120 / 150.** Unchanged in effect: notice mail, banner and flags at 120; order approval
  and portal checkout refused after 150; no override; lifted when the aged invoice
  settles.
- **Customer type.** A new master `customer_types` with a price tier (its promo), a
  payment term (its due days) and a credit age limit; the notice and freeze days stay
  company-wide. Setting or changing a customer's type copies the type's terms onto the
  customer (overwriting, audited); "Re-apply type terms" does it for every customer of
  the type.
- **Addresses.** The base's billing and shipping addresses are the main and the delivery
  address. The portal order now ships to the shipping address.

## Base edits (named here, as CLAUDE.md asks)

- `app/Domain/Sales/Contracts/AgingDate.php` (new seam), `app/Domain/Sales/InvoiceDateAging.php`
  (the base behaviour), `AppServiceProvider` binds them.
- `CreditCheck::oldestUnpaidDays`, `CreditCheck::needsNotice`, `TradeReports::aging`,
  the Age column of `SalesInvoiceResource`, the overdue hint of `CustomerFields`: read the
  seam instead of `trans_date` / `due_date`.
- `CustomerResource`: the Customer Type select on the form and a column on the list.
- `lang/id.json`: the new strings.

## Tests

`RightsMatrixTest` (seven roles, decisive cells, reshape, merge of Inventory into
Purchasing, delete guard), `AcceptanceClockTest`, `CustomerTypeTest`, `CheckoutTest`
(ship-to), plus the base suites the seam touches.
