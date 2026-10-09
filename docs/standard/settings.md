# Settings

Module group `settings`. 8 screens in the standard menu.

## Behaviours

- Preferences the release does not use yet (attachments, extra columns, financial categories, employee loans, the temporary payment account) are not offered on the screen; a stored value is kept. The company's fax prints on the letterhead; the data start date is the first day anything can be dated and the default date of opening balances.
- Number and date formats (the Other tab): the number convention (1.234.567,89 or 1,234,567.89) governs every amount shown and typed; quantities show up to the chosen decimals; unit prices on printed documents the chosen decimals; date fields use the chosen date format, while tables keep "17 Oct 2026".
- Language (the Other tab): the screens come in English or Bahasa Indonesia; the company's choice is everyone's default, and each user may pick their own on their profile (the avatar menu → Profile). Documents sent to customers (the tax invoice email and its PDF) go in the company's language. Month names and table dates follow the language; number and date formats are their own preferences. The template ships `lang/id.json` and `lang/id/*.php`; a client's own strings go in `app/Client/lang`.
- Preferences are the one source of truth for every switch: the company's identity, the modules that are on, the default accounts the posting layer uses, the business rules, the aging basis, the attachments and extra columns. Every change is audited with the old and the new value.
- Users are never deleted: they are deactivated, which stops sign-in at once (a session already open ends at its next click, back on the sign-in page with the reason) and keeps their name on everything they entered, approved or changed. Deactivating is refused for yourself, for the last active administrator, and for anyone an approval still needs: a named approver on an approval rule, the only active member of an approving group, or the person a waiting document cannot be approved without. Replace them on the rule first; a document already half approved waits for them to decide, or is edited so it asks again under the current rules. Reactivating restores access.
- The activity log records that a hidden field changed (a two-factor secret, recovery codes) but never its value; passwords and remember tokens are not logged.
- Access is by group: each group holds the five rights (view, create, update, delete, print) per screen plus the special rights (see cost, change selling price, see credit data, override credit limit, open a closed period, back-date, edit others' transactions, delete posted transactions, approve transactions, export). A user belongs to groups, may carry per-user grants or revocations, and is limited to the branches and warehouses assigned to them. A group's rights can be copied from another group.
- The special rights are enforced: without "see credit data" the customer's credit limits, the overdue notice on sales documents and the exposure in the approval prompt are hidden (stored values are kept); without "back-date" a document cannot be saved dated before today, or moved there (editing it on the date it already has is allowed); without "delete posted transactions" a posted document cannot be deleted and its Delete button is hidden; without "export" reports and the VAT return have no Export button.
- Branch limits: a branch closed to all users is open only to the users assigned to it. A user limited this way sees only the records of their branches (and those tagged to no branch) on every screen, may book only into their branches, and runs reports for one of their branches instead of all.
- The access window: an administrator may always work; an operator may when any of their groups allows the moment, either by the group's own time window or, for groups that follow Preferences, by the Restrictions tab (not restricted, restricted for everyone, or a time window). A window whose end is before its start runs past midnight. Outside it every page answers "access closed" with the hours.
- Seeded groups: only Administrator and Accounting may back-date and delete posted transactions.
- Six groups are seeded (Administrator, Accounting, Finance, Sales, Purchasing, Warehouse); the client reshapes them on the Access Groups screen.
- Numbering is configured per document type: a pattern of tokens (prefix, year, month, counter), the counter width, and when it resets; several series per type, one of them the default, each limited to chosen users or open to all.
- Print layouts are designed per document type: which blocks print, the heading, the footer, paper and orientation; one default per type.
- Approval rules (Transaction Approvers) say which documents need approval, from what amount, in which branch, for whose entries, by whom (users or groups) and under which condition: any one approver; at least two different people; every approver in any order; every approver in a set order. A group's place is filled by any one of its members. Only the document types a module registers are offered: quotations, orders, deliveries, invoices and returns on both sides, requisitions, goods receipts, vendor claims and payments, cash and bank vouchers and transfers, journal vouchers, expense accruals, inventory adjustments, item transfers and stock counts.
- One approval engine serves them all. At every save a document gets a request: approved on the spot when no active rule covers it, otherwise waiting under the covering rule with the highest "from amount". Sales orders wait only while the Sales Order Approval rule is on, and stock counts always wait; without a covering rule anyone with the "approve transactions" right approves them. The person who entered a document never approves it while Segregation of Duties is on, and each approval of a sales order passes the credit check.
- A waiting or rejected document is still in the books, but nothing is made from it (no Pull, no Process), it does not print, and no payment settles it. An edit that changes the amount, the branch or the lines asks for approval again; a note does not. Until someone decides, a request follows rule changes; decisions only ever grow.
- Seeded: inactive rules for sales returns, purchase returns and vendor claims, approved by any one member of Accounting.

## Screens

- [Preferences](#preferences)
- [Access Groups](#access-groups)
- [Users](#users)
- [Numbering](#numbering)
- [Print Layouts](#print-layouts)
- [Transaction Approvers](#transaction-approvers)
- [Add-on Store](#add-on-store) (not reproduced)
- [Financing Program](#financing-program) (not reproduced)

## Preferences

Menu key `company__preferences` · module `settings`

### Filters and inputs

#### Tab: Company

| Field | Column | Type | Required |
|---|---|---|---|
| Company name | `company__name` | text |  |
| Phone | `company__phone` | text |  |
| Fax | `company__fax` | text |  |
| Email | `company__email` | text |  |
| Address | `company__address` | textarea |  |
| Data start date | `company__data_start_date` | date |  |
| Fiscal year starts in | `company__fiscal_year_start_month` | select |  |

#### Tab: Features

| Field | Column | Type | Required |
|---|---|---|---|
| Multiple branches | `features__multi_branch` | toggle |  |
| Multiple currencies | `features__multi_currency` | toggle |  |
| Tax | `features__tax` | toggle |  |
| Transaction approval | `features__approval` | toggle |  |
| Fixed assets | `features__fixed_assets` | toggle |  |
| Budgets and targets | `features__budget_target` | toggle |  |
| Departments | `features__department` | toggle |  |
| Projects | `features__project` | toggle |  |
| Sales extras: check-ins, commissions, targets | `features__sales_extras` | toggle |  |
| Payroll entries and salary components | `features__payroll` | toggle |  |

#### Tab: Tax

| Field | Column | Type | Required |
|---|---|---|---|
| Registered company name | `tax__company_name` | text |  |
| VAT registration date | `tax__pkp_date` | date |  |
| VAT registration number | `tax__pkp_number` | text |  |
| Business type | `tax__business_type` | text |  |
| Company tax ID (NPWP) | `tax__npwp` | text |  |
| Business classification (KLU) | `tax__klu` | text |  |
| Business location ID (NITKU) | `tax__nitku` | text |  |

#### Tab: Sales

| Field | Column | Type | Required |
|---|---|---|---|
| Cost of returned goods taken from | `sales__cogs_source` | select |  |
| Sales return cost is charged | `sales__return_cost_charge` | select |  |
| Sales return cost account | `sales__return_cost_account` | select |  |
| Update item cost when a sales return is saved again | `sales__update_item_cost_on_return_resave` | toggle |  |
| New customers default to prices including tax | `sales__new_customer_inclusive_tax` | toggle |  |

#### Tab: Purchasing

| Field | Column | Type | Required |
|---|---|---|---|
| Last purchase price is updated by purchase invoices | `purchasing__last_price_updated_by_bill` | toggle |  |
| Only for invoices dated from | `purchasing__last_price_cutoff_date` | date |  |

#### Tab: Restrictions

| Field | Column | Type | Required |
|---|---|---|---|
| Access restriction | `restrictions__mode` | select |  |
| Access allowed from | `restrictions__from` | time |  |
| Access allowed until | `restrictions__until` | time |  |
| Administrators sign in with a second factor | `restrictions__administrator_two_factor` | toggle |  |

#### Tab: Default Accounts

| Field | Column | Type | Required |
|---|---|---|---|
| Accounts receivable | `accounts__receivable` | select |  |
| Customer down payments | `accounts__customer_down_payment` | select |  |
| Sales discounts | `accounts__sales_discount` | select |  |
| Accounts payable | `accounts__payable` | select |  |
| Vendor down payments | `accounts__vendor_down_payment` | select |  |
| Cost of goods sold | `accounts__cost_of_sales` | select |  |
| Inventory | `accounts__inventory` | select |  |
| Goods delivered, not yet invoiced | `accounts__goods_in_transit` | select |  |
| Rounding differences | `accounts__rounding` | select |  |
| Giros receivable (cheques received, not yet cleared) | `accounts__giro_receivable` | select |  |
| Giros payable (cheques issued, not yet cleared) | `accounts__giro_payable` | select |  |
| Realised exchange gains | `accounts__exchange_gain` | select |  |
| Realised exchange losses | `accounts__exchange_loss` | select |  |
| Salaries (a component without its own account) | `accounts__salary_expense` | select |  |
| Net pay owed to employees | `accounts__salary_payable` | select |  |
| Income tax Art. 21 withheld | `accounts__pph21_payable` | select |  |
| BPJS contributions owed | `accounts__bpjs_payable` | select |  |
| BPJS contributions paid by the employer | `accounts__bpjs_expense` | select |  |

#### Tab: Other

| Field | Column | Type | Required |
|---|---|---|---|
| Language | `other__language` | select |  |
| Number format | `other__decimal_format` | select |  |
| Decimals on quantities | `other__quantity_decimals` | select |  |
| Decimals on prices | `other__price_decimals` | select |  |
| Date format | `other__date_format` | select |  |
| Aging range (days) | `other__aging_range_days` | number |  |
| Age receivables from | `other__aging_basis` | select |  |
| Aging interval (days) | `other__aging_interval_days` | number |  |
| Commission is calculated from | `other__commission_basis` | select |  |

#### Tab: Business Rules

| Field | Column | Type | Required |
|---|---|---|---|
| Sales orders wait for approval before they can be delivered or invoiced | `rules__sales_order_approval` | toggle |  |
| Segregation of duties: whoever enters a document never approves or verifies it | `rules__segregation_of_duties` | toggle |  |
| Allow stock to go negative | `rules__allow_negative_stock` | toggle |  |
| Flag a customer when an invoice is unpaid for more than (days) | `rules__credit_notice_days` | number |  |
| Freeze a customer when an invoice is unpaid for more than (days) | `rules__credit_freeze_days` | number |  |

## Access Groups

Menu key `company__access-privilege` · module `settings`

### List

**Columns:** Group name · Users · Screens

**Actions:** Edit · Delete

### Form

**Section: General**

| Field | Column | Type | Required |
|---|---|---|---|
| Group name | `name` | text | yes |
| Access restriction | `restriction_type` | radio |  |
| From | `restricted_from` | time |  |
| Until | `restricted_until` | time |  |
| Notes | `memo` | textarea |  |

#### Tab: Users

| Field | Column | Type | Required |
|---|---|---|---|
| Members | `users` | checkbox list |  |

#### Tab: Screen rights

**Section: Settings**

| Field | Column | Type | Required |
|---|---|---|---|
| Preferences | `rights.company__preferences` | checkbox list |  |
| Access Groups | `rights.company__access-privilege` | checkbox list |  |
| Users | `rights.company__user-company` | checkbox list |  |
| Numbering | `rights.company__auto-number` | checkbox list |  |
| Print Layouts | `rights.company__print-layout` | checkbox list |  |
| Transaction Approvers | `rights.company__user-approval` | checkbox list |  |

**Section: Company**

| Field | Column | Type | Required |
|---|---|---|---|
| Currencies | `rights.company__currency` | checkbox list |  |
| Branches | `rights.company__branch` | checkbox list |  |
| Departments | `rights.company__department` | checkbox list |  |
| Projects | `rights.company__project` | checkbox list |  |
| Tax Codes | `rights.company__tax` | checkbox list |  |
| Payment Terms | `rights.company__payment-term` | checkbox list |  |
| Shipping Methods | `rights.company__shipment` | checkbox list |  |
| FOB Terms | `rights.company__freeonboard` | checkbox list |  |
| Salary Components | `rights.company__employee-fee` | checkbox list |  |
| Employees | `rights.company__employee` | checkbox list |  |
| Recurring Transactions | `rights.company__recurring` | checkbox list |  |
| Month-end Process | `rights.company__period-end` | checkbox list |  |
| Contacts | `rights.company__contact` | checkbox list |  |
| Memorized Transactions | `rights.company__memorize-transaction` | checkbox list |  |
| Calendar | `rights.company__calendar` | checkbox list |  |
| Activity Log | `rights.company__audit` | checkbox list |  |

**Section: General Ledger**

| Field | Column | Type | Required |
|---|---|---|---|
| Chart of Accounts | `rights.general-ledger__glaccount` | checkbox list |  |
| Expense Accruals | `rights.general-ledger__expense-accrual` | checkbox list |  |
| Payroll Entries | `rights.cash-bank__employee-payment` | checkbox list |  |
| Journal Vouchers | `rights.general-ledger__journal-voucher` | checkbox list |  |
| Budget Monitor | `rights.budget-target__accountbudget-monitor` | checkbox list |  |
| Budget Transfers | `rights.budget-target__accountbudget-transfer` | checkbox list |  |
| Budgets | `rights.budget-target__accountbudget-target` | checkbox list |  |
| Account History | `rights.general-ledger__account-history` | checkbox list |  |
| Journal Activity Log | `rights.company__audit-journal` | checkbox list |  |

**Section: Cash & Bank**

| Field | Column | Type | Required |
|---|---|---|---|
| Expense Claims | `rights.client__expense-claims` | checkbox list |  |
| Payments | `rights.cash-bank__other-payment` | checkbox list |  |
| Receipts | `rights.cash-bank__other-deposit` | checkbox list |  |
| Bank Transfers | `rights.cash-bank__bank-transfer` | checkbox list |  |
| Bank Statements | `rights.cash-bank__bank-statement` | checkbox list |  |
| Bank Book | `rights.cash-bank__bank-book` | checkbox list |  |
| Bank Reconciliation | `rights.cash-bank__bank-reconcile` | checkbox list |  |

**Section: Sales**

| Field | Column | Type | Required |
|---|---|---|---|
| Order Approvals | `rights.client__order-approvals` | checkbox list |  |
| Settlement Claims | `rights.client__settlement-claims` | checkbox list |  |
| Return Claims | `rights.client__return-claims` | checkbox list |  |
| Sales Quotations | `rights.customer__sales-quotation` | checkbox list |  |
| Sales Orders | `rights.customer__sales-order` | checkbox list |  |
| Delivery Orders | `rights.customer__delivery-order` | checkbox list |  |
| Sales Down Payments | `rights.customer__sales-downpayment` | checkbox list |  |
| Sales Invoices | `rights.customer__sales-invoice` | checkbox list |  |
| Sales Receipts | `rights.customer__sales-receipt` | checkbox list |  |
| Sales Returns | `rights.customer__sales-return` | checkbox list |  |
| Invoice Exchanges | `rights.customer__exchange-invoice` | checkbox list |  |
| Customer Categories | `rights.customer__customer-category` | checkbox list |  |
| Price Categories | `rights.customer__price-category` | checkbox list |  |
| Customers | `rights.customer__customer` | checkbox list |  |
| Price & Discount Adjustments | `rights.inventory__sellingprice-adjustment` | checkbox list |  |
| Salesman Commissions | `rights.company__salesman-commission` | checkbox list |  |
| Sales Targets | `rights.budget-target__sales-target` | checkbox list |  |
| Check-ins | `rights.customer__sales-check-in` | checkbox list |  |
| Customer Prices | `rights.client__customer-prices` | checkbox list |  |
| Customer Teams | `rights.client__teams` | checkbox list |  |

**Section: Purchasing**

| Field | Column | Type | Required |
|---|---|---|---|
| Purchase Orders | `rights.vendor__purchase-order` | checkbox list |  |
| Goods Receipts | `rights.vendor__receive-item` | checkbox list |  |
| Purchase Down Payments | `rights.vendor__purchase-downpayment` | checkbox list |  |
| Purchase Invoices | `rights.vendor__purchase-invoice` | checkbox list |  |
| Purchase Payments | `rights.vendor__purchase-payment` | checkbox list |  |
| Purchase Returns | `rights.vendor__purchase-return` | checkbox list |  |
| Vendor Claims | `rights.vendor__vendor-claim` | checkbox list |  |
| Vendor Prices | `rights.inventory__vendor-price` | checkbox list |  |
| Vendor Categories | `rights.vendor__vendor-category` | checkbox list |  |
| Vendors | `rights.vendor__vendor` | checkbox list |  |
| Payment Orders | `rights.vendor__transfer-order` | checkbox list |  |
| Vendor Transfers | `rights.vendor__multi-vendor-transfer` | checkbox list |  |

**Section: Inventory**

| Field | Column | Type | Required |
|---|---|---|---|
| Purchase Requisitions | `rights.vendor__purchase-requisition` | checkbox list |  |
| Item Transfers | `rights.inventory__item-transfer` | checkbox list |  |
| Inventory Adjustments | `rights.inventory__item-adjustment` | checkbox list |  |
| Stock Opname Orders | `rights.inventory__stock-opname-order` | checkbox list |  |
| Stock Opname Results | `rights.inventory__stock-opname-result` | checkbox list |  |
| Items & Services | `rights.inventory__item` | checkbox list |  |
| Warehouses | `rights.inventory__warehouse` | checkbox list |  |
| Units | `rights.inventory__unit` | checkbox list |  |
| Item Categories | `rights.inventory__item-category` | checkbox list |  |
| Item Brands | `rights.inventory__item-brand` | checkbox list |  |
| Order Fulfilment | `rights.inventory__backorder-inquiry` | checkbox list |  |
| Stock by Warehouse | `rights.inventory__stock-warehouse` | checkbox list |  |
| Minimum Stock | `rights.inventory__minimum-stock-item` | checkbox list |  |
| Price List | `rights.client__price-list` | checkbox list |  |

**Section: Fixed Assets**

| Field | Column | Type | Required |
|---|---|---|---|
| Fixed Assets | `rights.fixed-asset__fixed-asset` | checkbox list |  |
| Asset Categories | `rights.fixed-asset__fa-type` | checkbox list |  |
| Fiscal Asset Categories | `rights.fixed-asset__fiscal-fa-type` | checkbox list |  |
| Asset Changes | `rights.fixed-asset__fixed-asset-edited` | checkbox list |  |
| Asset Disposals | `rights.fixed-asset__fixed-asset-disposed` | checkbox list |  |
| Asset Transfers | `rights.fixed-asset__asset-transfer` | checkbox list |  |
| Assets by Location | `rights.fixed-asset__asset-location` | checkbox list |  |

**Section: Tax**

| Field | Column | Type | Required |
|---|---|---|---|
| e-Tax Invoice Export | `rights.company__efaktur-ctas` | checkbox list |  |
| Email Tax Invoice | `rights.customer__efaktur-send` | checkbox list |  |
| Legacy e-Tax Export | `rights.company__efaktur-online` | checkbox list |  |

**Section: Reports**

| Field | Column | Type | Required |
|---|---|---|---|
| Report Catalogue | `rights.report__report` | checkbox list |  |
| VAT Return | `rights.report__spt-masa` | checkbox list |  |
| Income Tax Art. 21 Return | `rights.report__formulir-1721-induk` | checkbox list |  |
| Withholding Slips | `rights.report__formulir-1721-bukti-potong` | checkbox list |  |

#### Tab: Special rights

| Field | Column | Type | Required |
|---|---|---|---|
| Rights not tied to one screen | `special_rights` | checkbox list |  |

## Users

Menu key `company__user-company` · module `settings`

### List

**Columns:** Name · Mobile number · Email · 2FA · Access type · Active

**Filters:** Access type · Active

**Actions:** Edit · Deactivate · Reactivate

### Form

**Section: Account**

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Email | `email` | text | yes |
| Mobile number | `phone` | text |  |
| Password | `password` | text |  |
| Access type | `access_type` | radio | yes |
| Active | `is_active` | toggle |  |

#### Tab: Access groups

| Field | Column | Type | Required |
|---|---|---|---|
| Groups | `accessGroups` | checkbox list |  |

#### Tab: Branches

| Field | Column | Type | Required |
|---|---|---|---|
| May work in these branches | `branches` | checkbox list |  |

## Numbering

Menu key `company__auto-number` · module `settings`

### List

**Columns:** Name · Transaction type · Example · Reset · Users · Default

**Filters:** Transaction type · Active

**Actions:** Edit · Delete

### Form

#### Tab: Numbering

**Section: Format**

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Transaction type | `transaction_type` | select | yes |
| Counter reset | `reset_rule` | select | yes |
| Counter digits | `counter_digits` | number | yes |
| Default for this transaction type | `is_default` | toggle |  |
| Active | `is_active` | toggle |  |

**Section: Components**

**Line grid "Pattern":** Component · Text

| Field | Column | Type | Required |
|---|---|---|---|
| Example for today | `example` | computed |  |

#### Tab: Users

| Field | Column | Type | Required |
|---|---|---|---|
| Available to all users | `used_all_user` | toggle |  |
| Users | `users` | checkbox list |  |

## Print Layouts

Menu key `company__print-layout` · module `settings`

### List

**Columns:** Name · Document · Default · Users

**Filters:** Document

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Document | `transaction_type` | select | yes |
| Default for this document | `is_default` | toggle |  |

#### Tab: Layout

| Field | Column | Type | Required |
|---|---|---|---|
| Paper | `settings.paper` | select | yes |
| Orientation | `settings.orientation` | select | yes |
| Heading (blank = document name) | `settings.title` | text |  |
| Copies | `settings.copies` | number |  |
| Company logo | `settings.show_logo` | toggle |  |
| Company address | `settings.show_company_address` | toggle |  |
| Tax ID | `settings.show_tax_id` | toggle |  |
| Bank account | `settings.show_bank_account` | toggle |  |
| Signature block | `settings.show_signature` | toggle |  |
| Item codes | `settings.show_item_code` | toggle |  |
| Units | `settings.show_unit` | toggle |  |
| Discount column | `settings.show_discount` | toggle |  |
| Tax column | `settings.show_tax` | toggle |  |
| Notes | `settings.show_notes` | toggle |  |
| Footer text | `settings.footer` | textarea |  |

#### Tab: Users

| Field | Column | Type | Required |
|---|---|---|---|
| Available to all users | `used_all_user` | toggle |  |
| Users | `users` | checkbox list |  |

## Transaction Approvers

Menu key `company__user-approval` · module `approval` · switched by Preferences → Features → Transaction approval

### List

**Columns:** Document · From amount · Approved by · Requested by · Branch · Condition · Active

**Filters:** Active · Document · Branch

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Document | `transaction_type` | select | yes |
| From amount | `min_amount` | number |  |
| Condition | `rule` | select | yes |
| Branch | `branch_id` | select |  |

**Fieldset: Who needs approval**

| Field | Column | Type | Required |
|---|---|---|---|
| Users | `requesters` | multi-select |  |

**Fieldset: Who approves**

| Field | Column | Type | Required |
|---|---|---|---|
| Access groups | `groups` | multi-select |  |
| Users | `approvers` | multi-select |  |

**Line grid "Approval order":** Slot

| Field | Column | Type | Required |
|---|---|---|---|
| Active | `is_active` | toggle |  |

## Add-on Store

Menu key `company__application` · module `settings`

Not reproduced: a vendor service of the original product.

## Financing Program

Menu key `company__capital-program` · module `settings`

Not reproduced: a vendor service of the original product.

