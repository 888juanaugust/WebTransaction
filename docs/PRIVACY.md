# Personal data

What an installation holds about people, why, for how long, and how a person's request is
answered, under UU 27/2022 on Personal Data Protection (UU PDP). The client company is the
controller of this data; this document describes what the system does, and each client
adapts the retention periods and the contact person to its own policy. Confirm with the
client's legal adviser before relying on it.

## What is held

| Whose | What | Where | Protected by |
|---|---|---|---|
| Users (staff) | name, email, mobile, password (hashed), two-factor secret, language | `users` | password hashing; the secret encrypted with the application key |
| Users | sign-in sessions and the IP address of each logged action | `sessions`, `audit_logs.ip` | sessions expire; the log is read on the Activity Log screen only |
| Customers, vendors | names, contact people, phone numbers, email, addresses, tax ID (NPWP, NITKU) and tax name | `customers`, `vendors`, contacts and addresses | screen rights and branch limits |
| Vendors | bank account numbers | `vendor_bank_accounts.bank_account` | **encrypted** with the application key |
| Employees | name, national ID (NIK), tax ID (NPWP), bank account number, tax status and dependants, pay setup, BPJS participation | `employees`, `employee_salary_components` | NIK, NPWP and bank account **encrypted**; the payroll screens need their rights |
| Employees | pay, income tax withheld, BPJS | `payroll_entries`, `payroll_entry_lines` | payroll rights |
| Customers, employees | tax files sent to the tax office (buyers' tax IDs; withholding slips with NIK and NPWP) | `storage/app/tax-filings` | not reachable from the web; download needs the screen's rights |
| Customers | tax invoice emails: the address it went to | `tax_invoice_mails`, `storage/app/tax-invoices` | append-only log |
| Everyone above | what changed on a record, by whom, when | `audit_logs` | append-only; encrypted and hidden fields are logged as "changed", never their value |

Uploaded import files and bank statements are deleted once read; spreadsheet exports are
deleted once downloaded.

## Why

- Customers and vendors: to sell, buy, invoice, collect and pay (performance of a contract).
- Tax IDs, tax files, withholding slips: tax law (UU KUP, UU PPh, UU PPN).
- Employees: the employment contract, payroll, BPJS and income tax Art. 21.
- Users, sessions and the activity log: the security of the system and the integrity of
  the books (who did what).

## How long

- Books, documents, tax files and the activity log: **ten years** after the end of the tax
  year (UU KUP Art. 28 (11)). The ledgers and the activity log are append-only; nothing in
  the system deletes them earlier.
- A customer, vendor or employee no longer active: kept while their documents are kept;
  their contact details may be anonymised earlier on request (below).
- Users: never deleted, only deactivated, so the log keeps naming who did what.
- Backups: as long as the books (see [DEPLOY.md](DEPLOY.md)).

## A person's request

Every step below is written to the Activity Log.

**Access, a copy of their data.** Open the customer, vendor or employee and use **Export
personal data** (needs the "export data" right). The JSON file holds the record, its
contacts, addresses and bank accounts, the documents made with them (numbers, dates,
amounts), the payroll lines of an employee, and when the record changed and by whom. Credit
data is left out for someone without the right to see it. Check the file before sending it
and send it to the person only, by a channel they confirmed.

**Correction.** Edit the record; the change is logged with its earlier value (encrypted
fields: that they changed).

**Erasure.** A customer, vendor or employee with no document can be deleted (the screen
refuses one in use). One with documents cannot: the documents are kept for the retention
period above. Instead, anonymise them: replace the name with "Former customer (number)",
empty the contacts, addresses, email, phone, tax IDs and bank accounts, and deactivate the
record. Two limits to tell the person:

- documents already issued (invoices, tax invoices, withholding slips) keep the name and
  tax ID they were issued with, because tax law requires them unchanged;
- the activity log is append-only and keeps earlier values of fields that are not
  encrypted (a name, an address) until the retention period ends.

**Objection, restriction.** Deactivate the record so it is no longer offered on new
documents.

Answer within the period UU PDP gives for the request (for most, 3 × 24 hours; confirm
the periods with the legal adviser), and note the request and the answer outside the
system.

## A breach

1. Contain it: take the system down (`php artisan down`), revoke the access that was
   used, change the passwords concerned.
2. If the server itself or a backup leaked, assume the application key leaked with it:
   generate a new key, keep the old one in `APP_PREVIOUS_KEYS`, and re-save the affected
   records so they are encrypted with the new key.
3. Within 3 × 24 hours, notify the people concerned and the authority in writing, with
   what leaked, when and how, and what was done (UU PDP Art. 46).
4. Keep a record: the Activity Log and the server logs from the time.

## Who else handles it

- The hosting provider (the server and the backups).
- The email provider (tax invoices and notifications).
- The tax office: files are uploaded to Coretax by a person, not by the system.

Each needs a written agreement covering personal data. Whether the client must register
the system as a private electronic system operator (PSE Lingkup Privat) depends on who
uses it; check with the client's legal adviser.
