# Purchasing

Module group `purchasing`. 12 screens in the standard menu.

## Behaviours

- The chain is requisition → order → goods receipt (from the order, partial) → invoice (from receipts or direct, moving stock when direct) → payment. Discounts and tax code per line; other charges to any account, including allocation into the goods' cost (landed cost).
- Down payments to a vendor are deducted on the invoice. Returns refer to a receipt or invoice and issue a debit automatically; claims record goods owed between the company and a vendor (quantities, not money), marked settled when the goods have moved.
- With "last purchase price is updated by purchase invoices" on (the default), an item's purchase price follows its latest purchase invoice dated from the cutoff date, net of discount and included tax, per base unit; deleting that invoice falls back to the one before.
- Vendor prices are the default purchase price per vendor and item. Payment orders instruct the bank to pay several vendor invoices; vendor transfers pay many vendors in one document.
- Vendors carry payment terms, tax status and identity, default tax, bank accounts, contacts and opening payables.
- A vendor's opening balances are the bills still open at the data start date: each posts on the data start date (Dr Opening Balance Equity / Cr payable), ages from its own date, is settled by payments like a bill and is locked once paid. "Discount on the total" is spread over the lines, so stock value and expense are net of it.
- With departments or projects on, orders, receipts, invoices, returns, vendor claims, down payments and payments carry a department and a project on the header and on every line and charge. A line's own wins; a line that names none, and the document's own legs (receivable or payable, tax, down payments), take the header's. A document made from another, or a line pulled from one, keeps its source's tags; the income statement filtered by a department shows its revenue and cost of sales.
- In a foreign currency: a vendor's currency opens their orders, receipts, bills, returns and down payments in it, at the rate on the document's date (changeable per document); amounts are typed in the currency and kept beside the rupiah ones the ledger reads. A payment settles bills in its own currency only; the payable leaves at the value it was booked at and the difference to what was paid is a realised exchange gain or loss. Money paid out of a foreign-currency bank account is valued at that account's average rate, its difference realised too.

## Screens

- [Purchase Orders](#purchase-orders)
- [Goods Receipts](#goods-receipts)
- [Purchase Down Payments](#purchase-down-payments)
- [Purchase Invoices](#purchase-invoices)
- [Purchase Payments](#purchase-payments)
- [Purchase Returns](#purchase-returns)
- [Vendor Claims](#vendor-claims)
- [Vendor Prices](#vendor-prices)
- [Vendor Categories](#vendor-categories)
- [Vendors](#vendors)
- [Payment Orders](#payment-orders)
- [Vendor Transfers](#vendor-transfers)

## Purchase Orders

Menu key `vendor__purchase-order` · module `purchasing`

### List

**Columns:** Number · Date · Vendor · Notes · Status · Total · Approval

**Filters:** Trans date · Vendor

**Actions:** Approve · Reject · Edit · Receive · Invoice · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Vendor | `vendor_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Warehouse · Processed · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Payment term | `payment_term_id` | select |  |
| Vendor bank account | `vendor_bank_account_id` | select |  |
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

**Actions:** Pull from requisitions

## Goods Receipts

Menu key `vendor__receive-item` · module `purchasing`

### List

**Columns:** Number · Delivery note No. · Date · Vendor · Notes · Status · Approval

**Filters:** Trans date · Received from

**Actions:** Approve · Reject · Edit · Invoice · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Received from | `vendor_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Form No. format | `series_id` | select |  |
| Form No. | `number` | text |  |
| Vendor's delivery note No. | `receive_number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Warehouse · Processed · Department · Project · Memo

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
| Ship date | `ship_date` | date |  |
| Shipping method | `shipment_id` | select |  |
| FOB | `fob_id` | select |  |

**Actions:** Pull from orders

## Purchase Down Payments

Menu key `vendor__purchase-downpayment` · module `purchasing`

### List

**Columns:** Number · Date · Vendor · Notes · Status · Age (days) · Total

**Filters:** Trans date · Vendor · Payment

**Actions:** Edit · Pay

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Vendor | `vendor_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Form No. format | `series_id` | select |  |
| Form No. | `number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Down payment

| Field | Column | Type | Required |
|---|---|---|---|
| Down payment | `amount` | number | yes |
| Tax | `tax_code_id` | select |  |
| Taxable | `taxable` | toggle |  |
| Prices include tax | `inclusive_tax` | toggle |  |
| Payment term | `payment_term_id` | select |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Vendor bank account | `vendor_bank_account_id` | select |  |
| Address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |

## Purchase Invoices

Menu key `vendor__purchase-invoice` · module `purchasing`

### List

**Columns:** Number · Invoice No. · Date · Vendor · Notes · Status · Age (days) · Total · Printed · Approval

**Filters:** Trans date · Vendor · Printed

**Actions:** Approve · Reject · Edit · Pay · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Vendor | `vendor_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Form No. format | `series_id` | select |  |
| Form No. | `number` | text |  |
| Vendor's invoice No. | `bill_number` | text |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Tax rate (KMK) | `tax_exchange_rate` | number |  |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Warehouse · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Payment term | `payment_term_id` | select |  |
| Vendor bank account | `vendor_bank_account_id` | select |  |
| Due date | `due_date` | date |  |
| Tax invoice No. (vendor) | `tax_invoice_number` | text |  |
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

**Line grid "Other charges":** Charge · Amount · Department · Project · Description · Into item cost

#### Tab: Down payments

**Line grid "Down payments":** Down payment · Amount deducted

**Actions:** Pull from receipts · Pull from orders

## Purchase Payments

Menu key `vendor__purchase-payment` · module `purchasing`

### List

**Columns:** Number · Date · Cheque No. · Cheque date · Vendor · Bank · Method · Notes · Giro · Amount paid · Approval

**Filters:** Trans date · Cheque date · Method · Bank · Paid to

**Actions:** Approve · Reject · Edit · Giro cleared · Giro bounced · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Paid to | `vendor_id` | select | yes |
| Bank | `bank_account_id` | select | yes |
| Payment method | `payment_method` | select | yes |
| Payment date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Voucher No. format | `series_id` | select |  |
| Voucher No. | `number` | text |  |
| Amount paid | `amount_preview` | computed |  |
| Currency | `currency_id` | select |  |
| Rate | `exchange_rate` | number |  |
| Cheque / giro No. | `cheque_no` | text |  |
| Cheque date | `cheque_date` | date |  |

#### Tab: Invoices

**Line grid "Line items":** Document · Open balance · Pay · Discount · Discount account

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Notes | `description` | textarea |  |

**Actions:** Pull every open document

## Purchase Returns

Menu key `vendor__purchase-return` · module `purchasing`

### List

**Columns:** Number · Date · Vendor · Return from · Notes · Credit used · Total · Approval

**Filters:** Trans date · Vendor · Printed

**Actions:** Approve · Reject · Edit · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Vendor | `vendor_id` | select | yes |
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

**Line grid "Line items":** Item · Quantity · Unit · Unit price · Disc % · Amount · Tax · Warehouse · Department · Project · Memo

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

## Vendor Claims

Menu key `vendor__vendor-claim` · module `purchasing`

### List

**Columns:** Number · Date · Claim type · Vendor · Notes · Delivery status · Approval

**Filters:** Trans date · Claim status · Claim type · Vendor

**Actions:** Approve · Reject · Edit · Mark settled

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Claim type | `claim_type` | select | yes |
| Vendor | `vendor_id` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Claim No. format | `series_id` | select |  |
| Claim No. | `number` | text |  |
| Date | `trans_date` | date | yes |

#### Tab: Line items

**Line grid "Line items":** Item · Quantity · Unit · Department · Project · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Vendor's address | `to_address` | textarea |  |
| Notes | `description` | textarea |  |

## Vendor Prices

Menu key `inventory__vendor-price` · module `purchasing`

### List

**Columns:** Number · Effective from · Vendor · Notes · Ends on

**Filters:** Trans date · Vendor

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Vendor | `vendor_id` | select | yes |
| Effective from | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Set an end date | `has_end_date` | toggle |  |
| Ends on | `end_date` | date |  |

#### Tab: Line items

**Line grid "Line items":** Item · Unit · New price

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Vendor Categories

Menu key `vendor__vendor-category` · module `purchasing`

### List

**Columns:** Category name · Default

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Category name | `name` | text | yes |
| Sub-category of | `parent_id` | select |  |
| Default category | `is_default` | toggle |  |

## Vendors

Menu key `vendor__vendor` · module `purchasing`

### List

**Columns:** Name · Vendor ID · Category · Type · Branch · Balance

**Filters:** Active · Category

**Actions:** Edit · Delete

### Form

**Section: General**

| Field | Column | Type | Required |
|---|---|---|---|
| Name | `name` | text | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Vendor ID format | `series_id` | select |  |
| Vendor ID | `number` | text |  |
| Category | `category_id` | select |  |
| Vendor type | `vendor_type_id` | select |  |
| Used in branch | `branch_id` | select | yes |
| Work phone | `work_phone` | text |  |
| Mobile | `mobile_phone` | text |  |
| WhatsApp | `whatsapp` | text |  |
| Email | `email` | text |  |
| Fax | `fax` | text |  |
| Website | `website` | text |  |
| Individual service provider (subject to income tax Art. 21) | `service_seller` | toggle |  |
| Active | `is_active` | toggle |  |

#### Tab: Address

**Fieldset: Payment address**

| Field | Column | Type | Required |
|---|---|---|---|
| Street | `bill_street` | textarea |  |
| City | `bill_city` | text |  |
| Postcode | `bill_zip_code` | text |  |
| Province | `bill_province` | text |  |
| Country | `bill_country` | text |  |

#### Tab: Contacts

**Line grid "Contacts":** Full name · Position · Email · Mobile

#### Tab: Purchasing

| Field | Column | Type | Required |
|---|---|---|---|
| Default discount (%) | `default_purchase_disc` | number |  |
| Payment term | `payment_term_id` | select |  |
| Currency | `currency_id` | select |  |
| Default invoice description | `default_invoice_desc` | textarea |  |
| Payable account | `payable_account_id` | select |  |
| Down payment account | `down_payment_account_id` | select |  |

**Line grid "Bank accounts":** Bank account number · Account holder · Bank

#### Tab: Tax

| Field | Column | Type | Required |
|---|---|---|---|
| Invoice totals include tax by default | `default_inc_tax` | toggle |  |
| Tax ID type | `wp_type` | select |  |
| Tax ID number | `wp_number` | text |  |
| Taxpayer name | `wp_name` | text |  |
| Business location ID (NITKU) | `nitku` | text |  |
| Transaction type | `document_code` | select |  |
| Tax address is the payment address | `tax_same_as_bill` | toggle |  |

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

| Field | Column | Type | Required |
|---|---|---|---|
| The vendor puts its own invoice number on bills | `use_bill_number` | toggle |  |
| Notes | `notes` | textarea |  |

## Payment Orders

Menu key `vendor__transfer-order` · module `purchasing`

### List

**Columns:** Number · Transfer deadline · Notes · Bank · Status · Total

**Filters:** Trans date · Status

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Transfer deadline | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Voucher No. format | `series_id` | select |  |
| Voucher No. | `number` | text |  |
| Payment method | `payment_method` | select | yes |
| Pay from bank | `bank_account_id` | select |  |

#### Tab: Invoices

**Line grid "Line items":** Invoice · Vendor · Invoice date · Invoice total · Open balance · Pay · Discount

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Vendor Transfers

Menu key `vendor__multi-vendor-transfer` · module `purchasing`

### List

**Columns:** Transfer deadline · Order · Vendor · Method · Bank · Vendor's account No. · Account holder · Amount

**Actions:** Pay the selected

