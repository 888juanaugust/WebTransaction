# Sales

Module group `sales`. 16 screens in the standard menu.

## Behaviours

- The chain is quotation → order → delivery (partial or several) → invoice (from one or several deliveries, or direct) → receipt. Fulfilment status (waiting, partial, processed, closed) is derived from quantities; "Pull" picks open upstream documents of the customer, "Process" opens the next document prefilled.
- Prices are typed freely by those with the right; otherwise they come from the customer's price category and the price adjustments in force on the document's date. Discount adjustments come from the customer's discount category (a price category), else their price category. An item that uses wholesale prices takes the highest quantity break its line reaches and is priced again when the quantity or unit changes; an item with a minimum sale quantity is not sold below it.
- Receipts propose the payment term's early-payment discount when paid within its discount days (on the open balance, tax included); the same holds for vendor payments. Discounts per line and per document; other charges to any account; tax included or excluded per document.
- The order's approval, when the Sales Order Approval rule is on, follows the approval rules and the credit check: amount limit (open receivables plus open orders), age limit, and the company's freeze days.
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

**Columns:** Notes · Category name · Default

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Category name | `name` | text | yes |
| Notes | `notes` | textarea |  |
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

