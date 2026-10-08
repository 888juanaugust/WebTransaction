# Central

The company system of an Indonesian B2B wholesaler of automotive spare parts, selling on
credit to workshops, parts shops and distributors. Three surfaces over one set of books: a
staff panel at `/admin`, a buyer portal at `/portal`, and a public, bilingual site that
never shows a price.

Central runs on the **August ERP base** ([888juanaugust/AugustERP](https://github.com/888juanaugust/AugustERP)):
a standard, modular ERP for trading companies — accounting, inventory, purchasing, sales,
cash and bank, fixed assets, tax and reports — on Laravel 13 and Filament 5 with
PostgreSQL, in English and Indonesian. The base was copied in once; everything that is
Central's own lives in its own layer (`app/Client`, `config/client.php`), so the base stays
readable as the base. [CLAUDE.md](CLAUDE.md) holds the rules every change keeps,
[docs/ROADMAP.md](docs/ROADMAP.md) the sub-projects that bring the previous system's
features onto the base, and [docs/standard](docs/standard/README.md) the functional
standard screen by screen, generated from the code (`php artisan erp:standard`).

## Modules

| Module group | Screens | Switch |
|---|---|---|
| Settings | Preferences, access groups, users, numbering, print layouts, approval rules | always on (approval rules switchable) |
| Company | Branches, currencies, tax codes, payment terms, shipping, FOB, employees, recurring and memorized transactions, month-end process, contacts, calendar, activity log | always on |
| General Ledger | Chart of accounts, journal vouchers, expense accruals, budgets, account history, journal activity log | always on (budgets switchable) |
| Cash & Bank | Payments, receipts, bank transfers, bank statements, bank book, reconciliation, giros | always on |
| Sales | Quotation → order → delivery → invoice → receipt, down payments, returns, invoice exchange, customers, price categories and adjustments; check-ins, commissions and targets | always on (sales extras on for Central) |
| Purchasing | Requisition → order → receipt → invoice → payment, down payments, returns, claims, vendor prices, payment orders, vendor transfers | always on |
| Inventory | Stock per warehouse at moving average, adjustments, transfers, stock opname, order fulfilment, stock inquiries, items, units, categories, brands | always on |
| Fixed Assets | Assets, categories, fiscal groups, monthly depreciation, changes, disposals, transfers, assets by location | on |
| Tax | Tax invoice export (bulk-import XML and the legacy CSV), serial numbers pasted back, VAT return | on |
| Reports | A catalogue of reports computed from the ledgers, with Excel export | always on |

Payroll, departments and projects stay off.

## Running it

```bash
composer install
cp .env.example .env && php artisan key:generate      # set DB_*, APP_URL
php artisan erp:install                                # prompts for the company, modules, administrator
npm install && npm run build
php artisan serve                                      # http://localhost:8000/admin
```

Non-interactive, for a server:

```bash
php artisan erp:install --no-interaction --company="…" --currency=IDR --locale=id \
  --admin-email=owner@example.test --admin-password='…'
```

Requirements: PHP 8.3 or 8.4 with `pdo_pgsql`, `intl`, `bcmath`; PostgreSQL 16; Redis;
Node 22. The schedule needs `php artisan schedule:run` every minute and a queue worker.
Production setup is in [docs/DEPLOY.md](docs/DEPLOY.md); the personal data the system
holds, for how long, and how a person's request is answered, in
[docs/PRIVACY.md](docs/PRIVACY.md).

## Tests

```bash
php artisan test            # against the central_test database (phpunit.xml)
vendor/bin/pint --test
php artisan erp:standard --check
npm run smoke -- /admin     # screenshots of running pages into storage/app/smoke (php artisan serve first)
npm run smoke:shell         # drives the workspace: tiles, tabs that keep their typing, reload, deep links
```

In a Claude Code cloud session, `.claude/hooks/session-start.sh` brings up PostgreSQL and
Redis, installs dependencies and installs the demo database.

## Translating

The English text of the UI is the translation key; `lang/id.json` and
`lang/id/{menu,fields,status}.php` hold the Indonesian, and `app/Client/lang/id.json`
Central's own strings. After changing the UI:

```bash
node tools/i18n/wrap-literals.mjs            # wraps any literal left outside __()
node tools/i18n/extract-strings.mjs id       # adds new keys to lang/id.json, empty, to translate
```

`TranslationGuardTest` fails the build on an unwrapped literal; `IndonesianTranslationTest`
fails it on a missing translation, a field without a label, or English text on an
Indonesian screen.
