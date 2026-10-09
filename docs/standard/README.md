# The standard

This is the functional standard of the template: what every module does, screen by screen, and the rules every installation keeps. A client installation starts from the whole standard and switches off the modules it does not need in Preferences → Features. Anything a client adds lives in its own layer (`app/Client`, `config/client.php`), never in the standard.

## Rules every installation keeps

- Money is stored as whole units of the base currency (BIGINT); tax base and tax are computed and stored per line; a document's totals are sums of its lines. A document in a foreign currency also keeps its own currency's amounts (in that currency's cents) beside them; only the base amounts are posted, taxed and reported, and exchange differences are realised on settlement, never revalued at month end.
- Postings (journal, stock movements, payment allocations) are derived from documents by one posting layer and are never written by hand; the audit log and document revisions only grow.
- A recorded document may be edited or deleted, subject to access rights, the closed-period lock (on the old and the new date) and blockers: reconciled, settled, referenced by a later document.
- Nothing still in use can be deleted: a customer, item, unit, account, tax code, department or any other record that a document, a ledger entry or another record points at is refused with the screens that use it ("used on Sales Invoices, the stock ledger"), and the advice to deactivate it where it can be deactivated. The refusal leaves everything as it was.
- Branches are a tag inside one set of books: one ledger, one stock, one average cost.
- Every transaction screen follows one pattern: a header (customer or vendor, date, a number drawn from a numbering series or typed by hand), a tab of lines, a tab of other information, a tab of other charges; the list opens with filters on date, status and printed state.
- An invoice is paid only when the allocations of payment documents reach its total; a sales order is approved only under the approval rules when the Sales Order Approval rule is on.

## Business rules (Preferences → Business Rules)

| Rule | Default | What it does |
|---|---|---|
| Sales Order Approval | off | Sales orders wait for approval before delivery or invoicing; who approves comes from Transaction Approvers, else the "approve transactions" right |
| Segregation of Duties | on | Whoever enters a document never approves or verifies it; switching this off is audited |
| Allow Negative Stock | off | When off, a delivery or adjustment that would take stock below zero is refused |
| Credit notice days | 0 (off) | A customer with an invoice unpaid longer than this is flagged on sales documents and the invoice list |
| Credit freeze days | 0 (off) | No order is approved for a customer with an invoice unpaid longer than this, until it is settled |

## Open questions

Confirm with the client's accountant before relying on these figures:

- The delivery order's journal: goods delivered but not yet invoiced go to the "Goods delivered, not yet invoiced" account named in Preferences; the invoice that follows moves them to cost of sales.
- Same-day costing order: movements are costed by date, receipts before issues on the same day, then in entry order.
- Retained earnings on the balance sheet are computed from the income statement (prior fiscal years' income), not formed by a closing entry.
- Withholding tax codes are modelled as a tax type but take part in no posting.
- VAT on payment, receipt and accrual lines is booked to the tax code's VAT in or out account; these lines do not appear in the VAT return or the e-Tax export.
- Fiscal depreciation follows the fiscal group's method and rate with the rest in the last year of the useful life; the groups, rates and the first-year month count are for the accountant to confirm.
- The early-payment discount is proposed on the open balance including VAT; whether the VAT on it should be corrected (a credit note on the tax invoice) is for the accountant.
- Cost is a moving average per warehouse; FIFO is not offered.
- Payroll and Art. 21 (config/pajak.php `pph21`, config/payroll.php `bpjs`): the TER tables of PP 58/2023, PTKP, occupational cost (5 %, 500 thousand a month worked) and the Art. 17 brackets; TER tax rounded down to the rupiah; the year worked out in December or the exit month without annualising part years; employer-paid health, work-accident and death premiums taxed as income; employee old-age and pension contributions (and zakat as a deduction kind) reduce net income; the BPJS wage is salary plus fixed allowances; health capped at 12 m, the pension cap set each March (2026: 11,086,300). Non-permanent workers, gross-up (tax borne by the employer) and the daily TER rates are not worked out. The Coretax slip files (BPMP and A1) follow the tax office's templates as known when written; they could not be fetched to check, so a test import in Coretax comes first (element names are configuration).
- Foreign currencies: VAT on a foreign document is computed in rupiah at the Minister of Finance's rate (the document's own rate when none is given); a foreign down payment is valued at its own rate and the difference realised when the invoice that deducts it is settled (not fixed at the down payment's date as IFRIC 22 would); a difference between the goods receipt's rate and the bill's stays in the stock's value. Unrealised month-end revaluation is not done.

## Modules

| Module group | Switchable modules in it | Screens | Page |
|---|---|---|---|
| Settings | `settings` (always on)<br>`approval` (Preferences → Features → Transaction approval) | 6 | [settings.md](settings.md) |
| Company | `company` (always on)<br>`departments` (Preferences → Features → Departments)<br>`projects` (Preferences → Features → Projects)<br>`payroll` (Preferences → Features → Payroll entries and salary components) | 16 | [company.md](company.md) |
| General Ledger | `general-ledger` (always on)<br>`payroll` (Preferences → Features → Payroll entries and salary components)<br>`budgets` (Preferences → Features → Budgets and targets) | 9 | [general-ledger.md](general-ledger.md) |
| Cash & Bank | `cash-bank` (always on) | 6 | [cash-bank.md](cash-bank.md) |
| Sales | `central-orders` (always on)<br>`sales` (always on)<br>`sales-extras` (Preferences → Features → Sales extras: check-ins, commissions, targets) | 17 | [sales.md](sales.md) |
| Purchasing | `purchasing` (always on) | 12 | [purchasing.md](purchasing.md) |
| Inventory | `purchasing` (always on)<br>`inventory` (always on) | 13 | [inventory.md](inventory.md) |
| Fixed Assets | `fixed-assets` (Preferences → Features → Fixed assets) | 7 | [fixed-assets.md](fixed-assets.md) |
| Tax | `tax` (Preferences → Features → Tax) | 3 | [tax.md](tax.md) |
| Reports | `reports` (always on)<br>`tax` (Preferences → Features → Tax)<br>`payroll` (Preferences → Features → Payroll entries and salary components) | 4 | [reports.md](reports.md) |

## How to read a page

Each screen lists what its list shows (columns, filters, actions) and what its form holds: every field with its label, the column it is stored in, its type and whether it is required; tabs, sections and line grids as the form groups them. A line grid is a table of lines inside the document, named by its columns. The reports page lists every report of the catalogue with its filters.

Generated by `php artisan erp:standard` from the Filament resources and pages; edit the code, then regenerate. The notes above each module come from `docs/standard/_notes/<module>.md` and are written by hand.
