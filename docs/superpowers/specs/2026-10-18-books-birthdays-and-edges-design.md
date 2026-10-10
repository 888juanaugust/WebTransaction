# Sub-project 10 — Books, birthdays and edges

Status: built (2026-10-18): year-end lock, month-close reminder, birthdays, the .xls refusal.

## Why

The owner asked for aset tetap, tutup buku monthly and annually, neraca, buku besar,
a customer birthday with a reminder and a calendar, and the price list in Excel. The
base already has the fixed-assets module, the monthly close, the Balance Sheet and
General Ledger reports, the Calendar and the Excel price list (Central's own); what is
missing is the annual close as a lock, a birthday on the people at a customer with a
reminder, Finance's rights on the books, and one edge on the price-list upload.

## Decisions

- **Rights.** Finance (keuangan) holds the fixed assets, the Month-end Process and every
  report (done in sub-project 7); the Owner alone reopens a closed month and closes the
  year.
- **Year-end close** is a lock, not a posted closing entry: the Owner closes a fiscal
  year once every month of it is closed, depreciation is posted for every month and both
  semester counts of the year are approved; no month of a closed year reopens. The
  balance sheet keeps computing retained earnings as the base does; the accountant is
  told this differs from the previous system's posted jurnal penutup. Reopening a year
  is the Owner's, with a reason, audited.
- **Month-close reminder.** When the next month to close is more than ten days past,
  Finance and the administrators get a bell and a mail, once per month.
- **Birthdays** live on a customer's contact persons (`customer_contacts.birth_date`):
  personal data under UU PDP, exported with the customer's other data, never mailed to
  the customer. Three days before and on the day, the customer's team and the
  administrators get a bell and a mail; the calendar shows birthdays to whoever may open
  Customers, within their branches.
- **Calendar** also carries the year end.
- **Price list**: a legacy `.xls` workbook is refused with "save as .xlsx first" instead
  of failing to parse.

## Base edits

`CustomerContact` casts and one DatePicker in the contacts repeater of `CustomerResource`;
`docs/PRIVACY.md`; `lang/id.json`.

## Tests

`YearEndTest`, `BirthdayTest`, `PriceListImportTest` (the `.xls` case).
