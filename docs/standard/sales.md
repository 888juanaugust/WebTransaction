# Sales

Module group `sales`. 23 screens in the standard menu.

## Behaviours

- The chain is quotation → order → delivery (partial or several) → invoice (from one or several deliveries, or direct) → receipt. Fulfilment status (waiting, partial, processed, closed) is derived from quantities; "Pull" picks open upstream documents of the customer, "Process" opens the next document prefilled.
- Central prices every selling line through one source (customer rules first, always): the customer's own rules on Customer Prices (a price or a discount, for one item or every item, from a quantity, between dates; the item's rule before the blanket one, the highest reached quantity break first), then the tier's rules for the item (the price category's adjustments and item prices), then the tier's blanket discount (a field on the price category, or the item's larger own discount), then the list price of the price list version in force on the document's date, last the base's item price; an item nothing prices is unpriced and its order cannot be approved. At approval every order line is stamped with its reason and the version it came from; the price itself was fixed when the line was saved.
- Prices are typed freely by those with the right; otherwise they come from the customer's price category and the price adjustments in force on the document's date. Discount adjustments come from the customer's discount category (a price category), else their price category. An item that uses wholesale prices takes the highest quantity break its line reaches and is priced again when the quantity or unit changes; an item with a minimum sale quantity is not sold below it.
- Receipts propose the payment term's early-payment discount when paid within its discount days (on the open balance, tax included); the same holds for vendor payments. Discounts per line and per document; other charges to any account; tax included or excluded per document.
- The order's approval, when the Sales Order Approval rule is on, follows the approval rules and the credit check: amount limit (open receivables plus open orders), age limit, and the company's freeze days.
- Central: every customer has a team, one sales and one marketing seat (Customer Teams), assigned by an administrator and audited; the sales works in the customer's branch, the marketing sees every branch. An order is approved by the customer's marketing seat or an administrator (Order Approvals); Sales never approves; a customer with no seat yet follows the base's approval rules. Approval under the Sales Order Approval rule reserves the order's goods in their warehouses in the stock reservations ledger (append-only, signed rows); a delivery pulled from the order consumes the hold, a delivery from another warehouse releases it, an unposted delivery gives it back, an edit that reopens the approval releases it, and goods held for other orders never leave. Nothing expires. An order whose goods sit in several warehouses is refused by the ordinary approve button and split from Order Approvals, where the plan is shown first: the home warehouse first, then the branch's other warehouses, then the rest by what they hold; the parent keeps the home share (or moves to the first warehouse that ships, renumbered in that branch), each other warehouse gets a sibling order in its branch (`split_parent_id`), and every piece is approved, credit-checked and reserved in one transaction, or none is.
- Central: money and stock move on two keys. A settlement claim (Settlement Claims) is the customer's sales or marketing seat's word, or the Owner's, that an invoice was paid, in whole or in part, and where, when and how — within the balance, one filed claim per invoice; Finance, never the filer, verifies it into a sales receipt in Finance's name that settles the invoice, or rejects it with a note. A return claim (Return Claims) is the sales seat's word that goods of an invoice come back to a warehouse and why, never more than was invoiced less what came back already; Inventory, never the filer, verifies it into a sales return in Inventory's name — only then does stock move and the customer get the credit. Filing is a view-create-print right; the Update right on the claim's screen is the verifier's key, and the Owner included never verifies what they filed.
- Central: an unpaid invoice older than the notice days (Business Rules; 120 for Central) is told once, by email in the company's language, to the customer and the active sales and marketing seats — the balance, the age and the day the account freezes — each night (`central:debt-notices`); the row is written before the mail, so nothing is sent twice. The freeze (strictly after the freeze days, 150) is the credit check at order approval and lifts the moment the invoice is paid. Collections lists the unpaid invoices a user chases (a seat's own customers, Finance's branch) with age, balance, the aging state, the last contact and the promise, bucketed by what to do today (promised for today, promise missed, overdue with no promise); a contact records how the customer was reached and what came of it; a promise needs a day ahead and moves no money — it is kept when receipts after the contact settle what was promised, missed when the day passes without that. A settlement claim is filed from the same row.
- Central's buyer portal (`/portal`): the company's customers sign in on their own guard with logins staff invite from Buyer Accounts (a signed set-password link, valid an hour, used once; deactivated and reactivated by staff; every step on the record). The home offers the last order again with the quantities editable, the open invoices with due dates and balances, and the last orders; above every page sit the free credit, what the buyer owes and what is on order, with a banner naming the day the account freezes, or that it is frozen. The catalogue shows every item on sale with the buyer's own price (ask us when nothing prices it) and whether their home warehouse has it, as a badge, never a number. The cart keeps lines in the item's units with no price stored; the estimate prices it today from the customer's rules. Checkout makes a sales order awaiting approval in the Portal system user's name with the buyer's login on it, numbered in the customer's branch, every quantity derived from the item and every price from the customer's rules on the server; a frozen account, an unpriced line or an empty cart refuses, and the amount limit is marketing's to judge at approval. Orders and invoices are the buyer's own; the order, the surat jalan and the invoice download as PDF from signed, short-lived links, for approved documents only. Untouched carts are pruned after 90 days.
- Deliveries move stock out at the moving average cost; the invoice moves goods delivered to cost of sales. Down payments carry their own tax and are deducted on the invoice. Returns refer to an invoice (or none), bring goods back and issue a credit automatically; the goods come back at the cost they left with on that invoice, or at the item's last purchase price, as Preferences choose, credited to the item's cost of sales account or a fixed account, and saving a return again re-costs it unless Preferences say to keep its first cost. Invoice exchange records the handing of invoices to the customer for payment scheduling.
- Customers carry billing, shipping and tax addresses, contacts, a category, a price and a discount category, a default salesperson, payment term and discount, tax identity for the tax invoice, credit limits (own or the parent's), and opening receivables.
- A customer's opening balances (the Opening balance tab) are the invoices still open at the data start date. Each is a document of its own: it posts on the data start date (Dr receivable / Cr Opening Balance Equity), keeps its own invoice and due dates for aging, is settled by receipts like an invoice, and is locked once payments are applied to it. Entering one needs the back-date right, and a customer holding any cannot be deleted. `php artisan erp:post-opening-balances` posts rows saved before this release.
- Check-ins, commissions and targets (the Sales extras module) are off by default.
- The commission statement (on the Salesman Commissions screen) shows what each salesperson earned in a period: net sales of the invoice lines that name them less their return lines, on the invoice's date or as customers pay (Preferences), each payment counting its share of the invoice. A rule applies when active and in force, covering the salesperson and meeting its requirement (sales value or quantity in a range, or once per quantity block); it pays a percentage of sales or gross profit, or a fixed amount. Commission levels are kept on the rule but not used, as employees carry no level. Nothing is posted.
- A sales target's Progress tab shows, per line, the quantity and net value sold within the target's dates and branch (less returns) for its item, category, salesperson or month, against the target.
- With departments or projects on, quotations, orders, deliveries, invoices, returns, down payments and receipts carry a department and a project on the header and on every line and charge. A line's own wins; a line that names none, and the document's own legs (receivable or payable, tax, down payments), take the header's. A document made from another, or a line pulled from one, keeps its source's tags; the income statement filtered by a department shows its revenue and cost of sales.
- "Discount on the total" is spread over the lines in proportion; each line's revenue, cost, commission and the sales reports are net of its share.
- In a foreign currency (Multiple currencies on and a foreign currency active): a customer's currency opens their documents in it, at the rate on the document's date from the Currencies screen; the rate (and, for VAT, the Minister of Finance's tax rate) can be changed per document. Prices, charges, down payments and receipts are typed in the document's currency and kept beside the rupiah amounts, which are the document's at its rate and are what the ledger, tax and reports read; VAT is computed in rupiah at the tax rate. Lines are pulled only from documents in the same currency, and a document made from another keeps its currency. A receipt settles documents in its own currency only; the receivable leaves at the value it was booked at and the difference to what was received is a realised exchange gain or loss (Preferences → Accounts). A giro in a foreign currency is refused.

## Screens

- [Order Approvals](#order-approvals)
- [Settlement Claims](#settlement-claims)
- [Collections](#collections)
- [Return Claims](#return-claims)
- [Sales Quotations](#sales-quotations)
- [Sales Orders](#sales-orders)
- [Delivery Orders](#delivery-orders)
- [Sales Down Payments](#sales-down-payments)
- [Sales Invoices](#sales-invoices)
- [Sales Receipts](#sales-receipts)
- [Sales Returns](#sales-returns)
- [Invoice Exchanges](#invoice-exchanges)
- [Customer Categories](#customer-categories)
- [Price Categories](#price-categories)
- [Customers](#customers)
- [Price & Discount Adjustments](#price-discount-adjustments)
- [Salesman Commissions](#salesman-commissions)
- [Sales Targets](#sales-targets)
- [e-Commerce Links](#e-commerce-links) (not reproduced)
- [Check-ins](#check-ins)
- [Customer Prices](#customer-prices)
- [Customer Teams](#customer-teams)
- [Buyer Accounts](#buyer-accounts)

## Order Approvals

Menu key `client__order-approvals` · module `central-orders`

### List

**Columns:** Customer · Order No. · Date · Branch · Total · Free credit · Stock

**Actions:** Open · Approve · Reject

## Settlement Claims

Menu key `client__settlement-claims` · module `central-claims`

### List

**Columns:** Invoice · Customer · Amount · Filed by · Filed · Status · Decided by

**Filters:** Status

**Actions:** Open

### Form

**Section: The invoice**

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Invoice | `sales_invoice_id` | select | yes |
| Amount received | `amount` | number | yes |
| How the money was handed over | `account` | textarea | yes |

## Collections

Menu key `client__collections` · module `central-collections`

### List

**Columns:** Customer · Invoice · Date · Due · Age · Balance · Aging · To do · Last contact · Promise

**Filters:** To do

**Actions:** Record contact · History · File a settlement claim

## Return Claims

Menu key `client__return-claims` · module `central-claims`

### List

**Columns:** Invoice · Customer · Warehouse · Lines · Reason · Filed by · Filed · Status

**Filters:** Status

**Actions:** Open

### Form

**Section: The invoice**

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Invoice | `sales_invoice_id` | select | yes |
| Back to warehouse | `warehouse_id` | select | yes |
| Reason | `reason` | textarea | yes |

**Section: What comes back**

**Line grid "Lines":** Invoice line · Quantity

## Sales Quotations

Menu key `customer__sales-quotation` · module `sales`

### List

**Columns:** Number · Date · Customer · Notes · Status · Total · Approval

**Filters:** Trans date · Ordered by · Printed

**Actions:** Approve · Reject · Edit · Create order · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Ordered by | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Salesperson · Processed · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Payment term | `payment_term_id` | select |  |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |
| Taxable | `taxable` | toggle |  |
| Prices include tax | `inclusive_tax` | toggle |  |
| Discount on the total (%) | `discount_percent` | number |  |

#### Tab: Other charges

**Line grid "Other charges":** Charge · Amount · Department · Project · Description

## Sales Orders

Menu key `customer__sales-order` · module `sales`

### List

**Columns:** Number · Date · Customer · Notes · Approval · Status · Total

**Filters:** Trans date · Ordered by · Approval · Printed

**Actions:** Edit · Approve · Reject · Deliver · Invoice · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Ordered by | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Order No. format | `series_id` | select |  |
| Order No. | `number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Warehouse · Salesperson · Processed · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Payment term | `payment_term_id` | select |  |
| PO number | `po_number` | text |  |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |
| Taxable | `taxable` | toggle |  |
| Prices include tax | `inclusive_tax` | toggle |  |
| Discount on the total (%) | `discount_percent` | number |  |
| Ship date | `ship_date` | date |  |
| Shipping method | `shipment_id` | select |  |
| FOB | `fob_id` | select |  |

#### Tab: Other charges

**Line grid "Other charges":** Charge · Amount · Department · Project · Description

**Actions:** Pull from quotations

## Delivery Orders

Menu key `customer__delivery-order` · module `sales`

### List

**Columns:** Number · Date · Customer · Shipping method · Notes · Status · Approval

**Filters:** Trans date · Ship to · Shipping method

**Actions:** Approve · Reject · Edit · Invoice · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Ship to | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Delivery No. format | `series_id` | select |  |
| Delivery No. | `number` | text |  |
| Shipping method | `shipment_id` | select |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Warehouse · Salesperson · Processed · Department · Project · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| PO number | `po_number` | text |  |
| FOB | `fob_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |

**Actions:** Pull from orders

## Sales Down Payments

Menu key `customer__sales-downpayment` · module `sales`

### List

**Columns:** Number · Date · Customer · Notes · Status · Age (days) · Total

**Filters:** Trans date · Customer · Printed

**Actions:** Edit · Receive payment

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Invoice No. format | `series_id` | select |  |
| Invoice No. | `number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Down payment

| Field | Column | Type | Required |
|---|---|---|---|
| Down payment | `amount` | number | yes |
| PO number | `po_number` | text |  |
| Tax | `tax_code_id` | select |  |
| Taxable | `taxable` | toggle |  |
| Prices include tax | `inclusive_tax` | toggle |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Payment term | `payment_term_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |

#### Tab: Payment info

| Field | Column | Type | Required |
|---|---|---|---|
| Paid | `paid` | computed |  |
| Deducted on invoices | `used` | computed |  |

## Sales Invoices

Menu key `customer__sales-invoice` · module `sales`

### List

**Columns:** Number · Date · Customer · Notes · Status · Age (days) · Total · NSFP · Printed · Approval

**Filters:** Trans date · Customer · Printed

**Actions:** Approve · Reject · Edit · Receive payment · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Invoice No. format | `series_id` | select |  |
| Invoice No. | `number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Warehouse · Salesperson · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Payment term | `payment_term_id` | select |  |
| PO number | `po_number` | text |  |
| Due date | `due_date` | date |  |
| Tax invoice serial (NSFP) | `nsfp` | text |  |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |
| Taxable | `taxable` | toggle |  |
| Prices include tax | `inclusive_tax` | toggle |  |
| Discount on the total (%) | `discount_percent` | number |  |
| Ship date | `ship_date` | date |  |
| Shipping method | `shipment_id` | select |  |
| FOB | `fob_id` | select |  |

#### Tab: Other charges

**Line grid "Other charges":** Charge · Amount · Department · Project · Description

#### Tab: Down payments

**Line grid "Down payments":** Down payment · Amount deducted

#### Tab: Payment info

| Field | Column | Type | Required |
|---|---|---|---|
| Tax invoice emails | `tax_invoice_mails` | computed |  |
| Paid | `paid` | computed |  |

**Actions:** Pull from deliveries · Pull from orders

## Sales Receipts

Menu key `customer__sales-receipt` · module `sales`

### List

**Columns:** Number · Date · Cheque No. · Cheque date · Customer · Bank · Notes · Credit used · Giro · Amount received

**Filters:** Trans date · Cheque date · Method · Bank · Received from

**Actions:** Edit · Giro cleared · Giro bounced · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Received from | `customer_id` | select | yes |
| Bank | `bank_account_id` | select | yes |
| Payment method | `payment_method` | select | yes |
| Payment date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Voucher No. format | `series_id` | select |  |
| Voucher No. | `number` | text |  |
| Amount received | `amount_preview` | computed |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Use credit notes | `use_credit` | toggle |  |
| Cheque / giro No. | `cheque_no` | text |  |
| Cheque date | `cheque_date` | date |  |

#### Tab: Invoices

**Line grid "Line items":** Invoice · Invoice date · Invoice total · Open balance · Pay · Discount · Discount account

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Notes | `description` | textarea |  |

**Actions:** Pull every open document

## Sales Returns

Menu key `customer__sales-return` · module `sales`

### List

**Columns:** Number · Date · Customer · Return from · Notes · Credit used · Total · Approval

**Filters:** Trans date · Customer · Return from · Printed

**Actions:** Approve · Reject · Edit · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Return No. format | `series_id` | select |  |
| Return No. | `number` | text |  |
| Return from | `return_type` | select | yes |
| Document | `source_key` | select |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Warehouse · Salesperson · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |
| Taxable | `taxable` | toggle |  |
| Prices include tax | `inclusive_tax` | toggle |  |
| Discount on the total (%) | `discount_percent` | number |  |

#### Tab: Other charges

**Line grid "Other charges":** Charge · Amount · Department · Project · Description

**Actions:** Pull the lines of the document

## Invoice Exchanges

Menu key `customer__exchange-invoice` · module `sales`

### List

**Columns:** Customer · Date · Exchange date · Number · Status · Invoice total

**Filters:** Trans date · Collect date · Customer

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Exchange date | `collect_date` | date | yes |
| Due date | `due_date` | date | yes |

#### Tab: Invoices

**Line grid "Line items":** Invoice · Invoice date · Due

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Customer Categories

Menu key `customer__customer-category` · module `sales`

### List

**Columns:** Category name · Default

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Category name | `name` | text | yes |
| Sub-category of | `parent_id` | select |  |
| Default category | `is_default` | toggle |  |

## Price Categories

Menu key `customer__price-category` · module `sales`

### List

**Columns:** Notes · Category name · Discount (%) · Default

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Category name | `name` | text | yes |
| Notes | `notes` | textarea |  |
| Discount on every item (%) | `blanket_discount_percent` | number |  |
| Default level | `is_default` | toggle |  |

## Customers

Menu key `customer__customer` · module `sales`

### List

**Columns:** Name · Primary contact · Customer ID · Category · Price category · Discount category · Tax address · Branch · Address · Payment term · Credit limit

**Filters:** Active · Category · Branch

**Actions:** Edit · Delete

### Form

**Section: General**

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Customer ID format | `series_id` | select |  |
| Customer ID | `number` | text |  |
| Category | `category_id` | select |  |
| Work phone | `work_phone` | text |  |
| Mobile | `mobile_phone` | text |  |
| WhatsApp | `whatsapp` | text |  |
| Email | `email` | text |  |
| Fax | `fax` | text |  |
| Website | `website` | text |  |
| Used in branch | `branch_id` | select |  |
| Active | `is_active` | toggle |  |

#### Tab: Billing address

**Fieldset: Billing address**

| Field | Column | Type | Required |
|---|---|---|---|
| Street | `bill_street` | textarea |  |
| City | `bill_city` | text |  |
| Postcode | `bill_zip_code` | text |  |
| Province | `bill_province` | text |  |
| Country | `bill_country` | text |  |

#### Tab: Contacts

**Line grid "Contacts":** Full name · Position · Email · Mobile

#### Tab: Shipping

| Field | Column | Type | Required |
|---|---|---|---|
| Same as the billing address | `ship_same_as_bill` | toggle |  |

**Fieldset: Shipping address**

| Field | Column | Type | Required |
|---|---|---|---|
| Street | `ship_street` | textarea |  |
| City | `ship_city` | text |  |
| Postcode | `ship_zip_code` | text |  |
| Province | `ship_province` | text |  |
| Country | `ship_country` | text |  |

**Line grid "Other delivery addresses":** Address

#### Tab: Sales

| Field | Column | Type | Required |
|---|---|---|---|
| Price category | `price_category_id` | select |  |
| Discount category | `discount_price_category_id` | select |  |
| Default salesperson | `salesman_id` | select |  |
| Payment term | `payment_term_id` | select |  |
| Default discount (%) | `default_sales_disc` | number |  |
| Currency | `currency_id` | select |  |
| Default invoice description | `default_invoice_desc` | text |  |

**Fieldset: Accounts**

| Field | Column | Type | Required |
|---|---|---|---|
| Receivable | `receivable_account_id` | select |  |
| Down payments | `down_payment_account_id` | select |  |
| Sales | `sales_account_id` | select |  |
| Item discounts | `item_discount_account_id` | select |  |
| Cost of goods sold | `cogs_account_id` | select |  |
| Sales returns | `sales_return_account_id` | select |  |
| Sales discounts | `sales_discount_account_id` | select |  |

#### Tab: Tax

| Field | Column | Type | Required |
|---|---|---|---|
| Invoice totals include tax by default | `default_inc_tax` | toggle |  |
| Tax ID type | `wp_type` | select |  |
| Tax ID number | `wp_number` | text |  |
| Taxpayer name | `wp_name` | text |  |
| Business location ID (NITKU) | `nitku` | text |  |
| Country code | `country_tax_code` | text |  |
| Transaction type | `document_code` | select |  |
| Tax invoices go to | `tax_invoice_email` | text |  |
| Tax address is the billing address | `tax_same_as_bill` | toggle |  |

**Fieldset: Tax address**

| Field | Column | Type | Required |
|---|---|---|---|
| Street | `tax_street` | textarea |  |
| City | `tax_city` | text |  |
| Postcode | `tax_zip_code` | text |  |
| Province | `tax_province` | text |  |
| Country | `tax_country` | text |  |

#### Tab: Opening balance

**Line grid "Opening balances":** Invoice date · Due date · Amount · Payment term · Number · Description · Open

#### Tab: Other

**Fieldset: Credit limit**

| Field | Column | Type | Required |
|---|---|---|---|
| Credit limit | `credit_limit_mode` | radio |  |
| Parent customer | `parent_customer_id` | select |  |
| Block when an invoice is older than | `credit_limit_age_enabled` | toggle |  |
| days | `credit_limit_age_days` | number |  |
| Block when receivables and open orders exceed | `credit_limit_amount_enabled` | toggle |  |
| amount | `credit_limit_amount` | number |  |

| Field | Column | Type | Required |
|---|---|---|---|
| Default warehouse | `default_warehouse_id` | select |  |
| Notes | `notes` | textarea |  |

## Price & Discount Adjustments

Menu key `inventory__sellingprice-adjustment` · module `sales`

### List

**Columns:** Number · Effective from · Price category · Notes · Ends on · Adjustment type · Active

**Filters:** Trans date · Active · Price category · Adjustment type

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Price category | `price_category_id` | select | yes |
| Adjustment type | `sales_adjustment_type` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Effective from | `trans_date` | date | yes |
| Ends on | `end_date` | date |  |
| Active | `is_active` | toggle |  |

#### Tab: Line items

**Line grid "Line items":** Item · Unit · From quantity · New value

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Salesman Commissions

Menu key `company__salesman-commission` · module `sales-extras` · switched by Preferences → Features → Sales extras: check-ins, commissions, targets

### List

**Columns:** Notes · Rule name · In force · Gain · Active

**Filters:** Active

**Actions:** Edit · Delete

### Form

#### Tab: Commission

| Field | Column | Type | Required |
|---|---|---|---|
| Rule name | `name` | text | yes |
| In force | `active_period` | radio |  |
| From | `from_date` | date |  |
| Until | `to_date` | date |  |
| Salespeople | `salesman_scope` | radio |  |
| Chosen salespeople | `salesmen` | checkbox list |  |
| Applies to levels | `levels` | checkbox list |  |

**Fieldset: Requirement**

| Field | Column | Type | Required |
|---|---|---|---|
| Requirement | `requirement` | radio |  |
| From | `requirement_from` | number |  |
| To | `requirement_to` | number |  |
| Per quantity | `requirement_qty` | number |  |

**Fieldset: Gain**

| Field | Column | Type | Required |
|---|---|---|---|
| Commission is | `gain_type` | select | yes |
| Percent | `gain_value` | number | yes |
| Amount (Rp) | `gain_amount` | number | yes |
| % of | `gain_basis` | select |  |

#### Tab: Other

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `notes` | textarea |  |
| Active | `is_active` | toggle |  |

## Sales Targets

Menu key `budget-target__sales-target` · module `sales-extras` · switched by Preferences → Features → Sales extras: check-ins, commissions, targets

### List

**Columns:** From · Until · Year · Target name · Branch · Target type

**Filters:** Target type

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Target name | `name` | text | yes |
| Target type | `target_type` | select | yes |
| Branch sales | `branch_id` | select |  |
| From | `from_date` | date |  |
| Until | `to_date` | date | yes |

#### Tab: Targets

**Line grid "Line items":** For · Quantity · Value

#### Tab: Progress

| Field | Column | Type | Required |
|---|---|---|---|
| Progress | `progress` | computed |  |

#### Tab: Notes

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `notes` | textarea |  |
| Analyst | `analyst_name` | text |  |

## e-Commerce Links

Menu key `customer__ecommerce-setting` · module `sales`

Not reproduced: a vendor service of the original product.

## Check-ins

Menu key `customer__sales-check-in` · module `sales-extras` · switched by Preferences → Features → Sales extras: check-ins, commissions, targets

### List

**Columns:** Date · Number · Customer (at check-in) · Salesperson · Transaction · Location

**Filters:** Trans date · Salesperson

**Actions:** Edit · Take an order

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Checked in at | `checked_in_at` | date and time | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Customer | `customer_id` | select |  |
| Customer name at check-in | `customer_name` | text | yes |
| Salesperson | `salesman_id` | select | yes |
| Order taken | `sales_order_id` | select |  |
| Latitude | `latitude` | number |  |
| Longitude | `longitude` | number |  |
| Notes | `notes` | textarea |  |

## Customer Prices

Menu key `client__customer-prices` · module `central-price-list`

### List

**Columns:** Customer · Item · From qty · Price · Discount (%) · Effective from · Effective until · Reason · Active

**Filters:** Customer · Active

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Item | `item_id` | select |  |
| From quantity (base units) | `min_base_quantity` | number | yes |
| Price per base unit | `price` | number |  |
| Discount (%) | `discount_percent` | number |  |
| Effective from | `effective_from` | date |  |
| Effective until | `effective_until` | date |  |
| Reason | `reason` | text | yes |
| Active | `is_active` | toggle |  |

## Customer Teams

Menu key `client__teams` · module `central-orders`

### List

**Columns:** Customer No. · Customer · Branch · Sales seat · Marketing seat

**Filters:** Sales seat · Marketing seat · Team complete

**Actions:** Assign team

## Buyer Accounts

Menu key `client__buyer-accounts` · module `central-portal`

### List

**Columns:** Customer · Name · Email · Invited · Last sign-in · Active

**Filters:** Customer · Active

**Actions:** Edit · Send invitation

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Customer | `customer_id` | select | yes |
| Name | `name` | text | yes |
| Email | `email` | text | yes |
| Phone | `phone` | text |  |

