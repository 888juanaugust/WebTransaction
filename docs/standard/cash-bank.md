# Cash & Bank

Module group `cash-bank`. 9 screens in the standard menu.

## Behaviours

- Any number of cash and bank accounts (accounts of type cash and bank), each with its own book and reconciliation.
- Payments and receipts are multi-line documents to or from any account, with tax and branch per line: a line's amount is before tax unless the document says amounts include tax, its tax goes to the tax code's VAT account (VAT in on payments and accruals, VAT out on receipts), and the supplier's tax invoice number may be noted on the line; a giro (cheque) recorded on a payment or receipt sits in the giro account until it clears or bounces, each a dated event.
- A payment line may settle an expense accrual or a payroll entry (the "Settles" column); a bounced giro reopens what it settled.
- Bank transfers move money between cash and bank accounts, with a fee.
- Bank statements are imported from CSV or Excel (the column headers are recognised in English and Indonesian); reconciliation matches statement lines to the book per account and period, and a reconciled line blocks changes to its document.
- The bank book is the mutation list of one account with a running balance.
- A cash or bank account may hold a foreign currency (chosen before its first posting): it then receives and pays only in that currency, and its journal lines keep the currency amount beside the rupiah one. There is no month-end revaluation: differences are realised when money moves.

## Screens

- [Payments](#payments)
- [Receipts](#receipts)
- [Bank Transfers](#bank-transfers)
- [Internet Banking](#internet-banking) (not reproduced)
- [Bank Statements](#bank-statements)
- [Bank Book](#bank-book)
- [Bank Reconciliation](#bank-reconciliation)
- [Virtual Accounts](#virtual-accounts) (not reproduced)
- [e-Payment](#e-payment) (not reproduced)

## Payments

Menu key `cash-bank__other-payment` · module `cash-bank`

### List

**Columns:** Number · Date · Cash / Bank · Cheque No. · Notes · Giro · Amount · Approval

**Filters:** Trans date · Cheque date · Cash / Bank

**Actions:** Approve · Reject · Edit · Giro cleared · Giro bounced · Memorize · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Cash / Bank | `bank_account_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Voucher No. format | `series_id` | select |  |
| Voucher No. | `number` | text |  |
| Amount | `amount_preview` | computed |  |

#### Tab: Payment details

**Line grid "Line items":** Settles · Account · Amount · Tax · Tax invoice No. · Branch · Department · Project · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Amounts include tax | `inclusive_tax` | toggle |  |
| Cheque / giro No. | `cheque_no` | text |  |
| Giro due date | `cheque_date` | date |  |
| Payee | `payee` | textarea |  |
| Notes | `description` | textarea |  |

**Actions:** Pull open accruals and payroll

## Receipts

Menu key `cash-bank__other-deposit` · module `cash-bank`

### List

**Columns:** Number · Date · Cash / Bank · Cheque No. · Notes · Giro · Amount · Approval

**Filters:** Trans date · Cheque date · Cash / Bank

**Actions:** Approve · Reject · Edit · Giro cleared · Giro bounced · Memorize · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Cash / Bank | `bank_account_id` | select | yes |
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Voucher No. format | `series_id` | select |  |
| Voucher No. | `number` | text |  |
| Amount | `amount_preview` | computed |  |

#### Tab: Receipt details

**Line grid "Line items":** Account · Amount · Tax · Tax invoice No. · Branch · Department · Project · Memo

#### Tab: Other info

| Field | Column | Type | Required |
|---|---|---|---|
| Branch | `branch_id` | select |  |
| Department | `department_id` | select |  |
| Project | `project_id` | select |  |
| Amounts include tax | `inclusive_tax` | toggle |  |
| Cheque / giro No. | `cheque_no` | text |  |
| Giro due date | `cheque_date` | date |  |
| Payer | `payer` | textarea |  |
| Notes | `description` | textarea |  |

## Bank Transfers

Menu key `cash-bank__bank-transfer` · module `cash-bank`

### List

**Columns:** Number · Date · From · To · Notes · Amount · Fees · Approval

**Filters:** Trans date · From · To

**Actions:** Approve · Reject · Edit · Print

### Form

| Field | Column | Type | Required |
|---|---|---|---|
| Date | `trans_date` | date | yes |
| Enter the number by hand | `manual_number` | toggle |  |
| Transfer No. format | `series_id` | select |  |
| Transfer No. | `number` | text |  |
| From cash / bank | `from_bank_account_id` | select | yes |
| Amount transferred | `amount` | number | yes |
| To cash / bank | `to_bank_account_id` | select | yes |
| Notes | `description` | textarea |  |

#### Tab: Transfer fees

**Line grid "Fees":** Account · Charged to · Amount · Memo

| Field | Column | Type | Required |
|---|---|---|---|
| Fees total | `fees_total_preview` | computed |  |

## Internet Banking

Menu key `cash-bank__internet-banking` · module `cash-bank`

Not reproduced: a vendor service of the original product.

## Bank Statements

Menu key `cash-bank__bank-statement` · module `cash-bank`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Bank | `bank_account_id` | select |  |
| From | `from` | date |  |
| Until | `until` | date |  |

### List

**Columns:** Date · Description · Movement · Type · Balance · Matched

## Bank Book

Menu key `cash-bank__bank-book` · module `cash-bank`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Cash / Bank | `bank_account_id` | select |  |
| From | `from` | date |  |
| Until | `until` | date |  |

### List

**Columns:** Date · Source No. · Cheque No. · Transaction type · Description · Movement · Type · Balance · Reconciled

## Bank Reconciliation

Menu key `cash-bank__bank-reconcile` · module `cash-bank`

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Bank | `bank_account_id` | select |  |
| Period from | `start` | date |  |
| Period until | `end` | date |  |
| Statement ending balance | `statement_balance` | number |  |

### List

**Columns:** Date · Source No. · Transaction type · Description · Debit · Credit · Cleared

**Actions:** Clear

## Virtual Accounts

Menu key `company__application-virtual-account` · module `cash-bank`

Not reproduced: a vendor service of the original product.

## e-Payment

Menu key `company__application-epayment` · module `cash-bank`

Not reproduced: a vendor service of the original product.

