# Buyer portal — design

Sub-project 4 of `docs/ROADMAP.md`. Status: approved 2026-10-12.

## Goal

Central's customers restock the same 15–20 SKUs on credit. The previous system gave them a
second Filament panel at `/portal` (own guard, invited logins, cart in PCS/SET/CTN,
checkout to a submitted order, reorder, catalogue with their prices, orders, invoices,
print-styled documents, a credit widget and an aging banner). The base has one panel, one
guard, a posting layer whose guards and audit rows are typed to staff users, signed print
routes behind the admin guard, and the client layer with Central's pricing, reservations,
credit notices and claims. Decisions with the user: orders are written in the name of a
**seeded Portal system user** (the buyer's login recorded on the order); logins are
**invite only** from the admin; the catalogue shows an **availability badge, no numbers**;
the amount limit **warns and lets the order through** to marketing, the freeze always
blocks.

## 1. The panel (`App\Client\Portal\PortalPanelProvider`)
- Registered by `ClientServiceProvider::register()` (`$this->app->register(...)`); the base
  stays untouched. `id('portal')->path('portal')->authGuard('customer')->login()
  ->passwordReset()->authPasswordBroker('customer_users')->profile(EditPortalProfile::class,
  isSimple: true)`, brand "{company} · Portal", colours from `config('client.theme')`, Geist
  via `LocalFontProvider`, `viteTheme('resources/css/filament/portal/theme.css')` (imports
  the admin theme's tokens and fonts, adds `@source` for `app/Client/Portal/**` and the
  portal views; the sidebar stays on, flat navigation), dark mode as the admin, `spa()`,
  `databaseTransactions()`.
- Middleware: the admin's stack minus the admin shell hooks; `SetLocale` replaced by
  `App\Client\Portal\Http\PortalLocale` (`Locales::apply($buyer->locale ?? Locales::companyDefault())`);
  `authMiddleware([Authenticate::class])`. The base's `SecurityHeaders` already applies.
- Discovery: `app/Client/Portal/Filament/{Resources,Pages,Widgets}`. Render hook
  `PAGE_START`: the credit strip (free credit, exposure, open orders) on every page, and the
  aging banner (notice sent / frozen on date) when it applies.
- **Base edit (listed):** `config/auth.php` — guard `customer` (session, provider
  `customer_users`), provider `customer_users` (eloquent, `App\Client\Models\CustomerUser`),
  broker `customer_users` (table `customer_password_reset_tokens`, expire 60, throttle 60).

## 2. Buyer logins
- `customer_users(id, customer_id FK, name, email unique, password, phone, locale nullable,
  is_active, last_login_at, invited_at, created_by FK users nullable, remember_token,
  timestamps)`; `customer_password_reset_tokens(email pk, token, created_at)`.
- `App\Client\Models\CustomerUser extends Authenticatable implements FilamentUser,
  HasAuditReference` (`Notifiable`): `canAccessPanel` = panel is `portal` and `is_active`
  and the customer is active. `last_login_at` set on `Login` event.
- Admin screen **Buyer Accounts** (`client__buyer-accounts`, Modul::Sales, Setup): a
  resource over `customer_users` — list (customer, name, email, active, invited, last
  login), create (customer, name, email, phone; the password is random and never shown),
  actions Send invitation (again), Deactivate / Activate. Creating sends
  `App\Client\Portal\Mail\PortalInvitation` (queued, company language): a set-password
  link from the broker (`Filament::getPanel('portal')->getResetPasswordUrl($token, $user)`),
  valid 60 minutes, single use. Audited `portal_access_granted`, `portal_invitation_sent`,
  `portal_access_revoked`. Rights: Administrator, Marketing, Finance ALL; Sales READ.
- Password reset on the portal through Filament's own pages and the `customer_users`
  broker; the `PasswordReset` event is audited `portal_password_reset`.

## 3. The Portal actor (`App\Client\Portal\PortalActor`)
- `App\Client\Seeders\PortalUserSeeder`: one staff user `portal@…` (email from
  `client.portal.actor_email`, default `portal@central.local`), operator, active, a
  random password nobody holds, member of the **Portal** access group (rights: Sales Orders
  view/create/print, Delivery Orders view/print, Sales Invoices view/print, Customers view,
  Items view; no special rights), every branch. `erp:install` seeds it with the client
  seeders.
- `PortalActor::run(callable $work)`: sets the Portal user on the `web` guard
  (`setUser`, not a login), `Auth::shouldUse('web')`, runs the work, restores the
  `customer` guard. Everything the base stamps with `auth()->id()` (created_by, audit,
  revisions, approval requests, reservations) then names the Portal user; the buyer is on
  `sales_orders.placed_by_customer_user_id` (client column) and in the audit meta.

## 4. Cart and checkout
- `portal_carts(id, customer_user_id unique, customer_id, po_number, note, timestamps)`,
  `portal_cart_lines(id, cart_id, item_id, unit_id, quantity decimal(18,4))`, unique
  `(cart_id, item_id, unit_id)`. No prices stored.
- `App\Client\Portal\Domain\Cart`: `forBuyer`, `add(item, unit, qty)` (item active, unit
  is the item's base unit or one of its units — CTN rows come from the price list's
  `qty_per_ctn`; merges on the same item and unit under `FOR UPDATE`), `setQuantity` (0
  removes), `remove`, `clear`, `count`.
- `CartEstimate::of(cart)`: per line the price from `Prices::resolve` on today for the
  line's base quantity (scaled to the unit), tax from the default tax code, line total; an
  unpriced line is flagged; availability from `Reservations::available(item, home
  warehouse)` as a badge (available ≥ qty / limited / ask us); totals; the customer's free
  credit (`credit_limit_amount − CreditCheck::exposure − openOrders`, when the limit is
  on) and a warning when the total exceeds it. Nothing persisted.
- `BuyerOrderPlacer::place(buyer, lines, poNumber, note)` → a `SalesOrder` awaiting
  approval: refuses an inactive customer, a frozen account (`CreditCheck::oldestUnpaidDays
  > freezeDays`), an empty basket, an unpriced line; inside `PortalActor::run` and one
  transaction: number from the Sales Order series with the customer's branch code, branch
  = customer's, `warehouse_id` on every line = the customer's default warehouse, `taxable`
  true, `inclusive_tax` = the customer's default, payment term, `po_number`, `description`
  = note, `placed_by_customer_user_id`; each line `item, unit, quantity, base_quantity`
  (derived through `UnitConverter::toBase`, never from the request), `unit_price` and
  `discount_percent` from the resolver, default tax code; `refreshTotal`,
  `DocumentRepository::created` (posts nothing; approval request created); audited
  `portal_order_placed` with the buyer. The amount limit is not checked here: the order
  goes to Order Approvals, where the base credit check and the seat decide.
- `Cart::checkout(buyer)`: locks the cart, places, clears the cart in the same transaction,
  returns the order. Reorder: `BuyerOrderPlacer::repeat(buyer, order, quantities)` — the
  order must be the buyer's customer's; quantities default to the order's, zero drops a
  line; placed directly (same checks).
- Command `central:prune-carts` (carts untouched for 90 days), scheduled nightly.

## 5. Portal screens (`app/Client/Portal/Filament`)
Every resource is read-only and scoped to the buyer's customer (`ScopedToBuyer` trait:
`where customer_id`, `canCreate/Edit/Delete` false). Navigation order: Home, Reorder
(the cart), Catalogue, Orders, Invoices.
- **Home** (dashboard): widgets Reorder last order (the last order's lines with editable
  quantities and "Order again"), Open invoices (number, date, due, days, balance; PDF),
  Last orders (number, date, status badge, total), and the credit strip + aging banner
  from the hook. No charts (not a priority).
- **Catalogue** (`Item` active, not scoped): number, brand, name, category, vehicle, part
  number, base unit, qty per carton (from the current price list item), **your price**
  (`Prices::resolve` qty 1, "ask us" when unpriced), availability badge; filters brand
  and category; search on name, number, part number, vehicle; row action Add to cart
  (unit select: base unit and the item's units; quantity ≥ 1). Pagination 25/50/100.
- **Cart** page: lines with unit, quantity (editable), price, total, availability, flag;
  totals, tax, free credit and the warning; PO number and note; Checkout (confirm) → the
  order's page; Clear.
- **Orders**: list (number, date, status: awaiting / approved / rejected, fulfilment
  status, total; filter status) and view (lines, totals, status, the deliveries made
  from it with surat jalan PDF links, the invoices; Reorder action).
- **Invoices**: list (default open; number, date, due date, days overdue, total, paid,
  balance) and view (lines, totals, payments received); PDF link.
- **Profile** (Filament simple profile): name, email, password, language (id / en).
- **Documents**: signed route `GET /portal/document/{alias}/{id}` (`filament.portal.document`,
  `signed` + the portal auth middleware; 30-minute links from `PortalDocuments::url`):
  alias in `sales_order | delivery | sales_invoice`, the document's customer must be the
  buyer's (404 otherwise), approved; rendered by `PdfRenderer::render` inside
  `PortalActor::run` and returned as a PDF download. The surat jalan is the delivery's
  print.

## 6. Rights and seeds
- `CentralGroupSeeder`: Buyer Accounts rights (above); the **Portal** group with the
  actor's rights. `PortalUserSeeder` in the module's `defaultSeeders()`.
- Module `central-portal` (`App\Client\Modules\PortalModule`): screens BuyerAccounts,
  morph `customer_user`, `portal_cart`, `portal_cart_line`, command and schedule.

## Base edits
`config/auth.php` (guard, provider, broker). `vite.config.js` gains the portal theme input
(build configuration). Nothing else.

## Tests

`tests/Feature/Client/Portal/`: `PortalAccessTest` (the login page, separate guards, an
inactive login or customer refused, a staff session useless on the portal, `/admin`
unreachable with a buyer session), `BuyerAccountsTest` (invitation mail and link, resend,
deactivate, audits), `CartTest` (add, merge, units and base quantities derived, estimate
from the customer's rules, availability, unpriced flag, free credit warning),
`CheckoutTest` (the order awaiting approval in the Portal user's name with the buyer on
it, prices from the resolver, the cart emptied, a double checkout makes one order, the
freeze and an unpriced line refuse), `ReorderTest`, `CatalogueTest` (prices per tier,
inactive items hidden, no row-by-row pricing of the whole catalogue), `PortalScreensTest`
(home widgets, orders and invoices scoped to the buyer, guessed ids 404), `PortalDocumentsTest`
(signed link, expiry, another customer's document 404).
