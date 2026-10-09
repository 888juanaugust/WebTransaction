# Roadmap

Central runs on the August ERP base, copied in once. The previous system (in this
repository's history before that commit) had features the base does not; they come onto
the base in the order below, one sub-project at a time. Each sub-project gets a spec in
`docs/superpowers/specs/` and an implementation plan before any code, and lands in the
client layer (`app/Client`, `config/client.php`) except where CLAUDE.md names a base file
that must change.

What the base already has is configured, not rebuilt: access groups and the
segregation-of-duties rule, the credit check with notice and freeze days, sales extras
(check-ins, commissions, targets), giro, down payments, the sales and purchasing chains,
stock opname and transfers, minimum stock, fixed assets, the Coretax XML and legacy CSV,
bank statements and reconciliation, periods, the report catalogue, printing, master imports.

| # | Sub-project | What it adds | Done when |
|---|---|---|---|
| 0 | Bootstrap (done 2026-10) | The base copied in, renamed Central, the client layer wired, CI and the session hook on Central's database names, this roadmap | `php artisan test` green on the copied base; the workspace shows "Central" |
| 1 | Branches and the order flow (done 2026-10) | Branches as cabang (code, coordinates), the branch in document numbers with a counter per branch; a team per customer (sales and marketing seats, Customer Teams); sales-order approval by the customer's marketing seat or an administrator; a reservations ledger (append-only, signed) written at approval under row locks, consumed by the delivery, released by an edit that reopens the approval, never expiring; the split of an order across warehouses from Order Approvals (home warehouse first, the branch's others, then the fullest, all pieces approved or none, `split_parent_id`) | An order with scattered goods approves as several pieces in one transaction, each reserved and numbered in its branch (`tests/Feature/Client`) |
| 2 | Price list | Price-list versions and items; imports with staged rows, the supplier-workbook parser (columns by position, categories from title rows, blockers vs notes), the five diff buckets, the safety brake with a typed acknowledgement, the full-replacement checkbox, export in the import's columns; a `PriceResolver` subclass (company overrides → tier rules → tier blanket discount → the version's list price → the base's price) whose reason is snapshotted on order lines | Export → edit HARGA → re-import publishes a new version; the brake stops a 25 % change until acknowledged; an order approved under one version keeps its prices after the next |
| 3 | Teams, claims, debt | A customer's sales and marketing seats (`TeamAssigner`: sales in the customer's branch, marketing global, audited); one active warehouse user per warehouse; two-key claims posted through the posting layer: pelunasan piutang (→ receipt), sales expense claim (→ cash payment), returns filed by sales and posted by inventory; the aging sweep (notice to the team at 120 days, freeze after 150 from the issue date, derived); the collection desk (contacts, promises, worklist) | A claim filed by one person can only be verified by another; a customer with an invoice past 150 days cannot order until it settles |
| 4 | Buyer portal | A second panel `/portal` on a `customer` guard (`customer_users`), scoped to the buyer and read-only; the cart (PCS / SET / CTN, checkout refused under a freeze, locked), orders placed as awaiting approval with base quantities derived from the item; reorder from the last order; widgets (available credit, open invoices, last orders, aging banner, spend and aging charts); invitations by a single-use password-reset link; invoice and surat jalan PDFs through signed print links | A pilot customer logs in, repeats last month's order with one edited quantity, and sees it awaiting approval with their credit updated |
| 5 | Public site | Bilingual pages (home, about, partners, roadmap, contact, two Indonesian-only legal pages, sign-in chooser), the language cookie, a content security policy, robots and sitemap, the promo carousel, the nearest branch from branch coordinates; company copy as `id` / `en` pairs guarded by a test, overridable by the Owner in Preferences | Every page renders in both languages with no price anywhere; the legal pages stay Indonesian |
| 6 | Operations and deployment | Encrypted nightly backups (`pg_dump` → XChaCha20 secretstream → disk, verified, retained 14 days plus monthly), `backup:restore`; health checks (database, Redis, queue, failed jobs, scheduler heartbeat, backup age, disk) with an hourly critical alert to the Owner; a ledger integrity sweep; launch readiness checks and attestations; the deploy kit (Caddy, supervisor, provisioning, logrotate, a `production` branch deploy workflow) | A practice restore of last night's backup into a scratch database succeeds; the launch check lists nothing open |

Not planned: a payment gateway, bank integrations, marketplace links, an app store,
financing programs, AI analysis, a mobile app, multi-currency, product reviews, a promo
or voucher engine, public price display.
