## Behaviours

- Any number of cash and bank accounts (accounts of type cash and bank), each with its own book and reconciliation.
- Payments and receipts are multi-line documents to or from any account, with tax and branch per line: a line's amount is before tax unless the document says amounts include tax, its tax goes to the tax code's VAT account (VAT in on payments and accruals, VAT out on receipts), and the supplier's tax invoice number may be noted on the line; a giro (cheque) recorded on a payment or receipt sits in the giro account until it clears or bounces, each a dated event.
- A payment line may settle an expense accrual or a payroll entry (the "Settles" column); a bounced giro reopens what it settled.
- Bank transfers move money between cash and bank accounts, with a fee.
- Bank statements are imported from CSV or Excel (the column headers are recognised in English and Indonesian); reconciliation matches statement lines to the book per account and period, and a reconciled line blocks changes to its document.
- The bank book is the mutation list of one account with a running balance.
- A cash or bank account may hold a foreign currency (chosen before its first posting): it then receives and pays only in that currency, and its journal lines keep the currency amount beside the rupiah one. There is no month-end revaluation: differences are realised when money moves.
