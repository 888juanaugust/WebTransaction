# General Ledger

Module group `general-ledger`. 9 screens in the standard menu.

## Behaviours

- The chart of accounts is a tree of accounts, each of one of sixteen types (cash and bank, accounts receivable, inventory, other current asset, fixed asset, accumulated depreciation, other asset, accounts payable, other current liability, long-term liability, equity, revenue, cost of goods sold, expense, other income, other expense) that decide where it appears on the statements; an account may carry an opening balance per start date and be deactivated.
- An account's opening balance is a document of its own, saved with the account through the document path (audited, with revisions, under the period lock and the back-date right) and dated on the data start date. Receivable and payable accounts take theirs per customer or vendor instead, so nothing is counted twice.
- The default accounts the posting layer uses (receivable, payable, down payments, inventory, cost of sales, goods delivered not yet invoiced, rounding, giros) are preferences, never constants.
- Journal vouchers are manual multi-line entries that must balance; expense accruals book expenses to any account with tax and branch per line; payroll entries book salaries by employee and component when the payroll module is on. "Calculate payroll" fills a monthly entry from every working employee's pay setup: their components, the BPJS contributions on salary and fixed allowances (health within its 12 m cap, pension within the cap in force on the pay date, old-age savings, work accident, death), and income tax Art. 21. Each month but the last withholds the TER rate of the employee's category on the month's taxable gross (salary, allowances, bonus and the employer's health, accident and death premiums; another entry of the same month counts too); December, or the month an employee leaves, works out the year on the Art. 17 brackets after occupational cost, pension contributions and PTKP, and withholds the rest, which may be negative. "Work out the tax again" recalculates the tax on lines typed by hand (a bonus run, say). Every line balances: net = gross − tax − contribution; employer contributions are expense owed to BPJS, employee contributions and deductions come off the net and are owed to BPJS or the deduction's own account. The lines stay editable.
- With departments or projects on, journal vouchers, expense accruals, payroll entries, payments and receipts carry a department and a project on the header and on each line. A line's own wins; a line that names none, and every leg the document books for itself (the bank, the payable, the tax), takes the header's. Account history filters by them.
- An expense accrual or a payroll entry is paid by a payment (Cash & Bank) whose line settles it: "Pay" on the list opens one, or "Pull open accruals and payroll" on the payment. The line debits the document's own payable account, never more than is open; the document's paid amount and status follow the allocations, and a paid one is locked until its payments are undone.
- Budgets hold an amount per account per month; the monitor compares them with the books; transfers move budget between accounts and months.
- Account history is the ledger of one account with a running balance; the journal activity log is the trail of changes to journals.
- Buku besar (Central) is the General Ledger report of the Reports group and the account history of one account; neraca is the Balance Sheet. Finance holds both with every report; nothing is rebuilt.

## Screens

- [Chart of Accounts](#chart-of-accounts)
- [Expense Accruals](#expense-accruals)
- [Payroll Entries](#payroll-entries)
- [Journal Vouchers](#journal-vouchers)
- [Budget Monitor](#budget-monitor)
- [Budget Transfers](#budget-transfers)
- [Budgets](#budgets)
- [Account History](#account-history)
- [Journal Activity Log](#journal-activity-log)

## Chart of Accounts

Menu key `general-ledger__glaccount` · module `general-ledger`

### List

**Columns:** Account number · Name · Account type · Balance · Active

**Filters:** Active · Account type

**Actions:** Edit · Delete

### Form

#### Tab: General

| Field | Column | Type | Required |
|---|---|---|---|
| Account type | `account_type` | select | yes |
| Sub-account | `is_sub` | toggle |  |
| Parent account | `parent_id` | select |  |
| Account number | `no` | text | yes |
| Name | `name` | text | yes |
| Notes | `memo` | textarea |  |
| Active | `is_active` | toggle |  |

#### Tab: Bank

| Field | Column | Type | Required |
|---|---|---|---|
| Bank | `bank_id` | select |  |
| Bank account number | `bank_account` | text |  |
| Account holder | `bank_account_name` | text |  |
| Currency | `currency_id` | select |  |

#### Tab: Opening balance

| Field | Column | Type | Required |
|---|---|---|---|
| Balance | `opening_amount` | number |  |
| As of | `opening_date` | date |  |

#### Tab: Users

| Field | Column | Type | Required |
|---|---|---|---|
| Available to all users | `used_all_user` | toggle |  |
| Users | `users` | checkbox list |  |

## Expense Accruals

Menu key `general-ledger__expense-accrual` · module `general-ledger`

### List

**Columns:** Number · Date · Due date · Total · Paid · Status · Notes · Approval

**Filters:** Status · Trans date

**Actions:** Approve · Reject · Edit · Pay

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Expense payable | `payable_account_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Expense No. format | `series_id` | select |  |
| Expense No. | `number` | text |  |

#### Tab: Expense lines

**Line grid "Line items":** Account · Amount · Tax · Tax invoice No. · Branch · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `total` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Due date | `due_date` | date | yes |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Amounts include tax | `inclusive_tax` | toggle |  |
| Notes | `description` | textarea |  |

## Payroll Entries

Menu key `cash-bank__employee-payment` · module `payroll` · switched by Preferences → Features → Payroll entries and salary components

### List

**Columns:** Number · Date · Due · Period · Payment type · Status · Notes · Net pay

**Filters:** Trans date · Period month · Status

**Actions:** Edit · Pay

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Payment type | `payment_type` | select | yes |
| Period month | `period_month` | select | yes |
| Period year | `period_year` | number | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Entry No. format | `series_id` | select |  |
| Entry No. | `number` | text |  |
| Date | `trans_date` | date | yes |
| Due date | `due_date` | date | yes |
| Net to pay | `totals` | computed |  |

#### Tab: Employees

**Line grid "Line items":** Employee · Component · Kind · Gross pay · Income tax · Contribution / deduction · Net pay · Department · Project · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Payable account | `expense_payable_account_id` | select | yes |
| Income tax payable | `tax_payable_account_id` | select |  |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Notes | `description` | textarea |  |

**Actions:** Pull every active employee · Calculate payroll · Work out the tax again

## Journal Vouchers

Menu key `general-ledger__journal-voucher` · module `general-ledger`

### List

**Columns:** —

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Number format | `series_id` | select |  |
| Number | `number` | text |  |
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |

#### Tab: Journal lines

**Line grid "Line items":** Account · Debit · Credit · Department · Project · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Total | `totals` | computed |  |

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Budget Monitor

Menu key `budget-target__accountbudget-monitor` · module `budgets` · switched by Preferences → Features → Budgets and targets

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Year | `year` | number |  |
| Month | `month` | select |  |
| Account | `account_id` | select |  |
| Branch | `branch_id` | select |  |

### List

**Columns:** No. · Account · Budget · Used · Remaining · Used %

## Budget Transfers

Menu key `budget-target__accountbudget-transfer` · module `budgets` · switched by Preferences → Features → Budgets and targets

### List

**Columns:** Number · Date · Year · From account · From month · To account · To month · Amount

**Filters:** Trans date

**Actions:** Edit

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Year | `year` | number | yes |
| Type | `scope` | select | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Transfer No. format | `series_id` | select |  |
| Transfer No. | `number` | text |  |
| Date | `trans_date` | date | yes |

**Section: From budget**

| Field | Column | Type | Required |
|---|---|---|---|
| Month | `from_month` | select | yes |
| Budget account | `from_account_id` | select | yes |
| Amount transferred | `amount` | number | yes |

**Section: To budget**

| Field | Column | Type | Required |
|---|---|---|---|
| Month | `to_month` | select | yes |
| Budget account | `to_account_id` | select | yes |

#### Tab: Notes

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `description` | textarea |  |

## Budgets

Menu key `budget-target__accountbudget-target` · module `budgets` · switched by Preferences → Features → Budgets and targets

### List

**Columns:** Year · Month · Type · Analyst · Notes · Total

**Filters:** Type

**Actions:** Edit · Delete

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Month | `month` | select | yes |
| Year | `year` | number | yes |
| Type | `scope` | select | yes |

#### Tab: Budget lines

**Line grid "Line items":** Account (income / expense) · Code · Amount

| Field | Column | Type | Required |
|---|---|---|---|
| Total budget | `total` | computed |  |

#### Tab: Notes

| Field | Column | Type | Required |
|---|---|---|---|
| Notes | `notes` | textarea |  |
| Analyst | `analyst_name` | text |  |

**Actions:** Pull every income and expense account

## Account History

Menu key `general-ledger__account-history` · module `general-ledger`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Account | `account_id` | select |  |
| From | `from` | date |  |
| Until | `until` | date |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |

### List

**Columns:** Date · Source No. · Transaction type · Notes · Movement · Type · Balance

## Journal Activity Log

Menu key `company__audit-journal` · module `general-ledger`

### List

**Columns:** Date · Number · Trans. No. · Transaction type · Rev. · Posted · By · Superseded · By

**Filters:** Transaction type · Active

