# Public site — design

Sub-project 5 of `docs/ROADMAP.md`. Status: approved 2026-10-13; built 2026-10-13.

## Goal

The company's open, indexed site: who Central is, what it carries, where its branches
are, how to become a customer, and the way in to the portal and the admin. Bilingual
(Indonesian first, English second), never a price, two legal pages that stay Indonesian.
The previous system had it (commit `0683d20`): eight pages under Indonesian slugs, a
`bahasa` cookie, a nonce CSP, robots and sitemap, a promo carousel from a JSON setting,
Owner overrides of the copy, and a nearest-branch finder from browser geolocation. The base
has no public routes (`/` redirects to `/admin`), a global `SecurityHeaders` middleware
that can only be tightened, `Locales`, `CompanyIdentity::letterhead()`, branches with
coordinates, and an unused `resources/css/app.css`.

Decisions with the user: **no nearest-branch finder** (a buyer's branch is set by the
admin; the portal shows unified, global stock), so no geolocation and no base edit; Owner
copy in a **client table behind a Website screen**; promos in a **table with image upload
and optional dates**; **no public catalogue**.

## 1. Routes and middleware (`app/Client/Site`)
- `app/Client/routes/site.php`, loaded by `ClientServiceProvider::boot()` inside an
  `$this->app->booted()` callback, after the base's `routes/web.php`, so the site's `/`
  replaces the base redirect (same method and URI: the later route wins). Controllers,
  never closures (`route:cache` in deploy). Group: `web` + `SiteLocale` +
  `SiteContentSecurityPolicy`; names `site.*`.
- Pages: `/` home, `/tentang-kami` about, `/mitra` partners, `/rencana-pengembangan`
  roadmap, `/kontak` contact (every active branch: name, address, phone, hours, a map
  link built from its coordinates), `/kebijakan-privasi`, `/syarat-penjualan` (both
  Indonesian whatever the cookie), `/masuk` sign-in chooser (portal login, admin login).
  `/bahasa/{kode}` (`id|en`) sets the `bahasa` cookie for a year and redirects to the
  referer when it is on this host, else `/`. `/robots.txt` (allow all, disallow
  `/admin`, `/portal`; sitemap link) and `/sitemap.xml` (the eight pages, `lastmod` =
  the latest site setting or promo change) as routes; the 0-byte `public/robots.txt` is
  removed so the route answers.
- `SiteLocale`: `Locales::apply($request->cookie('bahasa') ?: 'id')`; `<html lang>` and
  the copy follow it. The legal pages render through `Locales::using('id', …)`.
- `SiteContentSecurityPolicy`: a second, stricter policy on the site's responses
  (`default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;
  font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self';
  base-uri 'self'; object-src 'none'`). Two policies are both enforced, so the site runs
  under the tighter one with no inline script and no inline style: the carousel, the
  mobile menu and the language switch live in `resources/js/site.js` (bundled by Vite);
  JSON-LD is a data block CSP does not execute. The base header stays as it is.

## 2. Layout and design
- `resources/views/client/site/layout.blade.php` + one view per page under
  `resources/views/client/site/`. `resources/css/site.css` (Tailwind 4, `@source` the
  site views and `site.js`, Geist from `@fontsource-variable/geist`, tokens from
  DESIGN.md as `--color-*` variables, dark through `prefers-color-scheme`). Both Vite
  inputs added to `vite.config.js` (build configuration, as the portal theme was).
  `resources/css/app.css` stays untouched.
- Head: title, meta description, canonical, Open Graph, `favicon.svg` (a plain mark
  under `public/`), JSON-LD `Organization` with the branches as `location`. No third-party
  asset; motion respects `prefers-reduced-motion`. Built with the design skills
  (`impeccable`, `make-interfaces-feel-better`, `transitions-dev`).
- Every string the site shows is either company copy (a pair) or UI chrome through
  `__()`; copy is echoed with `{{ }}` so `TranslationGuardTest` sees no bare text.

## 3. Copy and the Owner's overrides
- `app/Client/config/site.php`: the company's copy as `['id' => …, 'en' => …]` pairs
  (tagline, about, why-us points, brands and categories blurbs, partner types, roadmap
  items, hours, contact, the legal values the terms cite: credit days, late payment, return
  window, delivery), merged as `site`. `App\Client\Site\Copy`: `text(key)`, `list(key)`,
  `pair(key)` in the current locale; a pair is an array keyed only `id`/`en`.
- `site_settings(key pk, value jsonb, updated_by, updated_at)`: the Owner's values,
  overlaid on the config by `App\Client\Site\SiteSettings` (`get`, `set` with
  `Auditor::log('site_setting_changed')` before/after). Cached per request; a change
  bumps the sitemap `lastmod`.
- Screen **Website** (`client__website`, Modul::Company, Setup): a page whose form has
  tabs Contact (phone, WhatsApp, email, city, hours id/en), About (short name, tagline,
  summary and profile, id/en), Partners (repeater: name, country, since, field and
  description id/en), Roadmap (repeater: title and description id/en, status), Legal
  (identity and the values the two legal pages cite). Saves through `SiteSettings`; a value
  put back to the config's own is forgotten. Administrator only.
- `SiteCopyTest`: every pair in the config has both sides non-blank; a setting overrides
  the config; the rendered English page shows the English side.

## 4. Promos and photos (the user's note: any picture, the goods or anything)
- One table, `site_images(id, kind promo|photo, title jsonb {id,en}, text jsonb {id,en},
  image_path, link, is_active, show_from, show_until, sort, timestamps)`, `RecordsActivity`.
  Image on the `public` disk under `promo/` (`storage:link` in `.claude/hooks/session-start.sh`
  and `docs/DEPLOY.md`; sub-project 6 carries it into the deploy kit). `SiteImage::live(kind)` =
  active and today within the dates (null = open), by `sort`.
- Screen **Website Images** (`client__site-images`, Modul::Company, Setup): `ManageRecords`
  over the table: kind, image upload (jpg/png/webp, 2 MB, with the editor), titles in both
  languages, a promo's text and link, active, dates, order. Administrator and Marketing ALL.
- The home renders the live promos as the carousel (one slide needs no script; `site.js`
  rotates several, pauses on hover and focus, stops under reduced motion) and the live
  photos as a gallery with captions. The sitemap's `lastmod` is the latest setting or image.

## 5. Portal: global stock (the user's note)
- `Reservations::availableAnywhere(itemId)`: on hand minus held, summed over active
  warehouses. `CartEstimate::availability()` and the Catalogue badge read it instead of the
  home warehouse (the order still ships from the home warehouse and is split at approval
  when the goods sit elsewhere). `CartTest`/`CatalogueTest` adjusted.

## Base edits (listed)
None in code. `public/robots.txt` removed (static file would shadow the route);
`vite.config.js` gains the two site inputs; `.claude/hooks/session-start.sh` gains
`storage:link`.

## Files
- `app/Client/routes/site.php`; `app/Client/Site/{Copy,SiteSettings,Sitemap}.php`;
  `app/Client/Site/Http/{SiteLocale,SiteContentSecurityPolicy,SiteController,
  LanguageController,RobotsController}.php`; `app/Client/config/site.php`.
- `app/Client/Models/{SiteSetting,SiteImage}.php`; migrations `2026_10_13_0001..0002`.
- `app/Client/Modules/SiteModule.php` (key `central-site`, screens Website, SiteImages,
  morph `site_setting`, `site_image`); `CentralScreen::{Website,SiteImages}`;
  `app/Client/Filament/Pages/Website.php`, `app/Client/Filament/Resources/SiteImages/…`.
- Views `resources/views/client/site/*.blade.php`; `resources/css/site.css`,
  `resources/js/site.js`; `public/favicon.svg`.
- Rights in `CentralGroupSeeder`; `lang/id.json`; notes `docs/standard/_notes/company.md`;
  spec `docs/superpowers/specs/2026-10-13-public-site-design.md`; roadmap row 5;
  `tools/ui-smoke/smoke.mjs` accepts the public pages without login.
- Tests `tests/Feature/Client/Site/{SiteRoutesTest, SiteLanguageTest, SiteCopyTest,
  WebsiteScreensTest}.php`; `tests/Feature/Client/Portal/CartTest` update.

## Order of work (TDD per step, commit per step)
1. Spec; commit.
2. Routes, middleware, layout, the eight pages with config copy; `SiteRoutesTest`
   (every page 200 in both languages, no `Rp` and no price anywhere, legal pages
   Indonesian under the `en` cookie, `/` no longer redirects, the second CSP header,
   robots and sitemap), `SiteLanguageTest` (cookie, on-host redirect only).
3. Settings table, `SiteSettings`, Website screen; `SiteCopyTest`, `WebsiteScreensTest`.
4. Promos table, screen, carousel; `PromoTest`.
5. Portal global stock; tests.
6. Design pass with the skills (both themes, phone width, reduced motion), `site.js`,
   favicon, smoke; notes, `erp:standard`, i18n scripts, full suite, Pint, build;
   roadmap row; commit; push.

## Verification
- `php artisan test`, `vendor/bin/pint --test`, `php artisan erp:standard --check`,
  `npm run build`, `npm run smoke -- /` and `/kontak`.
- Manual: open `/` in Indonesian, switch to English, every page follows except the two
  legal pages; no price on any page; the Owner changes the phone on Website and the
  contact page shows it; a promo with an image and a date range appears on the home only
  inside the range; `curl -I /` shows two CSP headers; `/robots.txt` and `/sitemap.xml`
  answer; a buyer's catalogue badge says Available for stock held in another branch.
