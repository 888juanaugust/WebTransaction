# Reports

Module group `reports`. 6 screens in the standard menu.

## Behaviours

- Every report in the catalogue takes its filters (period, branch, and the report's own), is computed from the journal and the stock ledger when it opens, and exports to Excel. Nothing is stored.
- With departments or projects on, the income statement and the general ledger also filter by department (the department with every department under it) and by project. The balance sheet and the other reports stay whole-company.
- The VAT return summarises output and input tax per tax period, headed by the company's VAT identity (registered name, tax ID, VAT registration number and date, business type, KLU). The Income Tax Art. 21 Return shows a month's payroll per employee (taxable gross, TER category and rate, tax withheld) and exports the month's BPMP slips for Coretax's import; Withholding Slips shows each employee's A1 for a year (income by the slip's rows, occupational cost, pension contributions, PTKP, taxable income, the year's tax and what was withheld), prints it, and exports the A1 slips of the employees whose year or employment ended. Each export is kept as a filing numbered from the withholding-slip series and can be downloaded again. A2 (civil servants) does not apply.
- The balance sheet shows the income of fiscal years before the current one as retained earnings and the current fiscal year's as "Net income this year"; the fiscal year starts in the month Preferences name. The income statement, statement of changes in equity and cash flow open on the fiscal year to date; the other reports on the current month.
- Receivable and payable aging use the buckets Preferences set (an interval up to a range, then everything older: current, 1–30, 31–60, 61–90 and over 90 days to start) and age from the invoice date or the due date as Preferences say, which a report may change.
- Aging and the customer and vendor statements include opening balances, aged from their own invoice date. A statement shows the balance brought forward, then every invoice or bill, down payment, opening balance, return and receipt or payment in the period, with the running balance owed; a bounced giro counts for nothing.
- Receivable and payable aging and the customer and vendor statements take a Currency filter while foreign currencies are in use: left empty, every document in rupiah (as without currencies); a currency, only its documents in its own amounts.
- Product Analytics (Central) is six views over one set of filters (period, branch, warehouse, category, brand), read from the invoice lines, the return lines, the stock movements and the stock cache: most sold (ranked by quantity, net value or distinct customers, with the cost of what left and the margin), least taken (stock on hand with no delivery in the last 30, 90, 180 or 365 days), never sold, oldest stock, most returned (returned against sold, the rate), turnover (units sold against the stock on hand, months of cover). Cost and margin need the "see cost" right; Excel export needs the export right; the top ten of the view draws as bars.

## Screens

- [Product Analytics](#product-analytics)
- [Report Catalogue](#report-catalogue)
- [VAT Return](#vat-return)
- [AI Analysis](#ai-analysis) (not reproduced)
- [Income Tax Art. 21 Return](#income-tax-art-21-return)
- [Withholding Slips](#withholding-slips)

## Product Analytics

Menu key `client__product-analytics` · module `central-warehouse`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| View | `view` | select | yes |
| Ranked by | `rank` | select |  |
| Idle for | `days` | select |  |
| From | `from` | date |  |
| Until | `until` | date |  |
| Branch | `branch_id` | select |  |
| Warehouse | `warehouse_id` | select |  |
| Category | `category_id` | select |  |
| Brand | `brand_id` | select |  |

### List

**Columns:** Item code · Item name · Quantity · Invoices · Customers · Net value

## Report Catalogue

Menu key `report__report` · module `reports`

A read-only screen with its own layout.

## VAT Return

Menu key `report__spt-masa` · module `tax` · switched by Preferences → Features → Tax

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| From | `from` | date |  |
| Until | `until` | date |  |
| Show | `kind` | select |  |
| Document kind | `document_code` | select |  |
| Search | `search` | text |  |

### List

**Columns:** Tax · Tax invoice No. · Transaction No. · Date · Document kind · Description · Tax base (DPP) · VAT · Customer / Vendor

## AI Analysis

Menu key `report-insight-analysis` · module `reports`

Not reproduced: a vendor service of the original product.

## Income Tax Art. 21 Return

Menu key `report__formulir-1721-induk` · module `payroll` · switched by Preferences → Features → Payroll entries and salary components

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Month | `month` | select |  |
| Year | `year` | select |  |

### List

**Columns:** Employee · Tax ID / NIK · PTKP · TER category · Gross · Rate (%) · Income tax · Worked out by

## Withholding Slips

Menu key `report__formulir-1721-bukti-potong` · module `payroll` · switched by Preferences → Features → Payroll entries and salary components

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Year | `year` | select |  |

### List

**Columns:** Employee · Months · Gross · Occupational cost · Pension contributions · Net income for the year · PTKP · Taxable income · Tax for the year · Withheld · Still to withhold · Status

**Actions:** Print A1

## Reports in the catalogue

Every figure is computed from the journal and the stock ledger when the report opens; nothing is stored. Each report exports to Excel.

| Report | Group | Needs | Filters | What it shows |
|---|---|---|---|---|
| Customer Statement | Sales | always on | From · Until · Branch · Customer · Currency | One customer's invoices, down payments, opening balances, returns and receipts in the period, with the balance brought forward and the running balance owed. |
| Open Sales Orders | Sales | always on | From · Until · Branch | Order lines not yet fully delivered or invoiced, with what is left and its value. |
| Receivable Aging | Sales | always on | From · Until · Branch · Age from · Currency | Open receivables per customer by age at the period's end, in the buckets Preferences set (current, 1–30, 31–60, 61–90 and over 90 days to start). |
| Sales by Customer | Sales | always on | From · Until · Branch | Invoiced sales per customer in the period: invoices, quantity, amount, VAT and total. |
| Sales by Item | Sales | always on | From · Until · Branch | Invoiced sales per item in the period: invoices, quantity, amount, VAT and total. |
| Sales by Salesperson | Sales | always on | From · Until · Branch | Invoiced sales per salesperson in the period: invoices, quantity, amount, VAT and total. |
| Open Purchase Orders | Purchasing | always on | From · Until · Branch | Order lines not yet fully received or invoiced, with what is left and its value. |
| Payable Aging | Purchasing | always on | From · Until · Branch · Age from · Currency | Open payables per vendor by age at the period's end, in the buckets Preferences set (current, 1–30, 31–60, 61–90 and over 90 days to start). |
| Purchases by Item | Purchasing | always on | From · Until · Branch | Invoiced purchases per item in the period: invoices, quantity, amount, VAT and total. |
| Purchases by Vendor | Purchasing | always on | From · Until · Branch | Invoiced purchases per vendor in the period: invoices, quantity, amount, VAT and total. |
| Vendor Statement | Purchasing | always on | From · Until · Branch · Vendor · Currency | One vendor's bills, down payments, opening balances, returns and payments in the period, with the balance brought forward and the running balance owed. |
| Inventory Value by Warehouse | Inventory | always on | Warehouse · Item category | Quantity on hand, average cost and value of every item per warehouse, from the stock ledger's cost cache. |
| Stock Card | Inventory | always on | From · Until · Item · Warehouse | One item's movements in and out with the running quantity and value, per warehouse or across all. |
| Cash & Bank Mutations | Cash & Bank | always on | From · Until · Branch | Opening balance, money in, money out and closing balance of every cash and bank account over the period. |
| Depreciation Schedule | Fixed Assets | `fixed-assets` | From · Until · Books · Asset category | Every asset with its cost, the period's depreciation, accumulated depreciation and book value at the period's end, in the commercial books or the tax books. |
| Balance Sheet | Financial | always on | From · Until · Branch | Assets, liabilities and equity as at the end of the period, current and non-current, with the income to date. |
| Cash Flow Statement | Financial | always on | From · Until · Branch | Cash in and out by operating, investing and financing activities, from the counter-accounts of every cash posting. |
| General Ledger | Financial | always on | From · Until · Branch · Department · Project · Account | Every posting of every account in the period, with running balances. |
| Income Statement | Financial | always on | From · Until · Branch · Department · Project | Revenue, cost of sales, expenses and the net income of the period, per branch when asked. |
| Statement of Changes in Equity | Financial | always on | From · Until · Branch | Equity at the start, capital movements, the period's income, equity at the end. |
| Trial Balance | Financial | always on | From · Until · Branch | Every account with its opening balance, the period's debits and credits, and the closing balance. |

