# Tax

Module group `tax`. 3 screens in the standard menu.

## Behaviours

- Output tax invoices export to the tax office's bulk-import XML; the older CSV layout stays selectable so an earlier filing can be reproduced. The serial numbers the tax office returns are pasted back onto the invoices. The e-Tax screen filters by document (a tax invoice to a buyer with a tax ID, or aggregated when the buyer has none) and by status (draft, exported, numbered); a document exported more than once shows as a replacement, and the Info column says what the file will make of it (no buyer tax ID, items without a goods code). The VAT return can be saved for its period as a numbered record with its totals. An invoice with a serial is locked as reported; "Clear serial" on the e-Tax screen unlocks it for a correction and keeps the old serial in the activity log.
- Email Tax Invoice sends a customer its tax invoice once the serial is back: the Coretax PDF and the company's own invoice as a PDF, to the customer's tax-invoice address (Customers → Tax), else their email. Coretax PDFs are uploaded in bulk and matched to their invoice by the serial in the file's name, or failing that in its text; a file that matches none is reported and can be attached to its invoice by hand. Sending is queued and every send and its outcome (sent, failed, skipped) is a row of an append-only log, shown on the invoice; an invoice already sent under its serial is sent again only by "Send again", and a send that runs twice goes out once. Mail settings come from the environment (`MAIL_*`); the template's default writes mail to the log.

## Screens

- [e-Tax Invoice Export](#e-tax-invoice-export)
- [Email Tax Invoice](#email-tax-invoice)
- [Legacy e-Tax Export](#legacy-e-tax-export)

## e-Tax Invoice Export

Menu key `company__efaktur-ctas` · module `tax` · switched by Preferences → Features → Tax

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Tax | `kind` | select |  |
| Month | `month` | select |  |
| Year | `year` | select |  |
| From day | `day_from` | select |  |
| To day | `day_to` | select |  |
| Branch | `branch_id` | select |  |
| Document | `document` | select |  |
| Status | `status` | select |  |
| Search | `search` | text |  |

### List

**Columns:** Tax date · Transaction No. · Tax invoice No. · Tax base (DPP) · VAT · Document · Status · Correction · Tax ID · Name · Info

**Actions:** Clear serial · Export selected

## Email Tax Invoice

Menu key `customer__efaktur-send` · module `tax` · switched by Preferences → Features → Tax

### List

**Columns:** Number · Date · Customer · Tax invoice serial · Send to · Coretax PDF · Status

**Filters:** Trans date · Coretax PDF

**Actions:** Attach PDF · Send · Send again · Send selected

## Legacy e-Tax Export

Menu key `company__efaktur-online` · module `tax` · switched by Preferences → Features → Tax

### Filters and inputs

| Field | Column | Type | Required |
|---|---|---|---|
| Tax | `kind` | select |  |
| Month | `month` | select |  |
| Year | `year` | select |  |
| From day | `day_from` | select |  |
| To day | `day_to` | select |  |
| Branch | `branch_id` | select |  |
| Document | `document` | select |  |
| Status | `status` | select |  |
| Search | `search` | text |  |

### List

**Columns:** Tax date · Transaction No. · Tax invoice No. · Tax base (DPP) · VAT · Document · Status · Correction · Tax ID · Name · Info

**Actions:** Clear serial · Export selected

