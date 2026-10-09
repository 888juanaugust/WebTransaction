# Price list — design

Sub-project 2 of `docs/ROADMAP.md`. Status: approved 2026-10-10; built 2026-10-10.

## Goal

Central sells at list prices that come from a supplier's price list, reviewed and published
as a **version**; customers sit in tiers (price categories); some customers have their own
deals. Every line's price is explained by a reason and the version it came from. The base
prices a line through a `final`, static `PriceResolver` with two call sites, nothing per
customer, nothing versioned, and no reason recorded.

Decisions taken with the owner:

- **Customer rules first, always**: customer SKU rule → customer blanket rule → tier SKU
  rule → tier blanket discount → list price. A deal with the customer beats the tier.
- Export is **.xlsx**; import accepts .xlsx and .csv. The export format is the import
  format.

## 1. One pricing seam in the base

- `App\Domain\Sales\Contracts\Prices` (interface):
  `resolve(?Customer, Item, ?int $unitId, $date = null, ?string $baseQuantity = null): array`
  returning `price`, `discount_percent`, `source`, and optionally `reason` and
  `version_id`.
- `App\Domain\Sales\ListPrices` implements it by delegating to `PriceResolver::resolve`:
  the base's behaviour, unchanged. `AppServiceProvider` binds the interface to it.
- The two call sites (`SalesLinesTab`, `SellingPriceGuard`) resolve through the interface.
  `ClientServiceProvider` binds it to `App\Client\Domain\Pricing\CentralPrices`, which
  wins because the client provider boots last.

## 2. Tiers are the base's price categories

A tier is a `price_categories` row; customers already carry `price_category_id`. A tier's
rule for one item — a price or a discount, with quantity breaks — is the base's selling
price adjustment or item price, edited on their own screens. A tier's blanket discount is
a new column, `price_categories.blanket_discount_percent`, on the Price Categories form.

## 3. Customer price rules

`customer_price_rules(customer_id, item_id nullable = every item, min_base_quantity,
price nullable, discount_percent nullable, effective_from, effective_until, reason,
created_by)`; a rule carries a price or a discount, not both, not neither. Screen
**Customer Prices** (Sales group, setup): the rules, by customer and item, with their
dates and reason. Rights: Administrator and Inventory in full, Marketing and Sales read.

## 4. Versioned list prices

- `price_list_versions(effective_from, status draft | published | superseded,
  published_at, published_by, import_id, note)`; `price_list_items(version_id, item_id,
  price, qty_per_ctn, is_active)`, one row per item and version.
- The version in force on a date is the latest published or superseded one whose
  `effective_from` is on or before the date, the highest id on a tie. A draft never prices.
- `items.sell_price` is written at publish as a **cache** of the current version, so the
  base's item screen, reports and last fallback agree with the list; the versions are the
  record. Items also gain `part_number`, `vehicle` and `product_type` from the file,
  shown read-only on the Items form.

## 5. How a price is resolved (`CentralPrices`)

Quantity is in base units; a price is per base unit and scaled to the line's unit by the
item's unit ratio. Each step returns as soon as it prices.

1. **Customer rules** in force on the date: the item's own rules first, then the
   customer's blanket rules; in each group the highest `min_base_quantity` the quantity
   reaches wins, the newest on a tie. A price rule gives its price (`customer_price`); a
   discount rule gives the list price less the discount (`customer_discount`).
2. **Tier item rules**: the base resolver's price adjustment for the category
   (`tier_price`) or item price for the category (`tier_item_price`), with the discount
   it carries.
3. **Tier blanket discount**: the category's `blanket_discount_percent` above zero gives
   the list price with the larger of that discount and the discount the base resolver
   carries for the item (`tier_discount`).
4. **List price**: the version in force gives the item's price (`list_price`, with the
   version id). An item missing from the version, inactive in it, or no version at all
   falls back to the base resolver's unit or base price (`base_price`); a zero there is
   `unpriced`.

An order with an unpriced line is refused approval.

## 6. The price reason on order lines

`sales_order_lines.price_reason` and `price_list_version_id`. When an order is approved,
every line is resolved again on the order's date and both columns are written: the base's
guard already made the saved price match on save (unless the user may change prices); the
stamp records why. The splitter copies line attributes, so the pieces carry them. Prices
themselves are never changed at approval.

## 7. The import pipeline

- `price_list_imports(original_filename, stored_path, checksum, format supplier |
  canonical, is_full_replacement, effective_from, status uploaded | parsing | parsed |
  failed | published | discarded, row_count, blocker_count, note_count, diff, parse_error,
  version_id, uploaded_by, approved_by, approved_at, brake_acknowledgement, note)` and
  `price_list_import_rows(import_id, sheet, row_number, raw, kode, merk, kategori,
  tipe_produk, mobil, part_number, description, qty_per_ctn, satuan_dasar, harga, aktif,
  catatan, status ok | note | blocker, issues, diff_bucket, harga_lama, item_id)`.
- Upload: a workbook or CSV, up to 20 MB, stored under `price-lists/` and kept forever
  with its sha256; the parse runs as a queued job (sync in tests), idempotent: it wipes the
  import's rows and parses again; status goes uploaded → parsing → parsed, or failed with
  the error.
- **Supplier workbook parser**: every sheet; each row cut to 24 columns and classified —
  blank; header (3 or more cells equal to a header token, compared whole and
  case-insensitively); title (one filled non-numeric cell), which is a banner (`^PRICE
  LIST`, `^*? HARGA SEWAKTU`, ignored), a category (contains one of the known categories)
  or a product type (anything else, kept as typed, a leading `*` included); else data.
  A category title clears the carried product type. Columns by position: MOBIL 0, PART
  NUMBER 1, DESCRIPTION 2, QTY/CTN 3, KODE 4, HARGA 5, MERK 6. The base unit is PCS; the
  parser never guesses SET. The sheet name is never used as the brand.
- **Canonical parser**: the first sheet; the header row must be exactly `KODE | MERK |
  KATEGORI | TIPE_PRODUK | MOBIL | PART_NUMBER | DESCRIPTION | QTY_PER_CTN |
  SATUAN_DASAR | HARGA | AKTIF | CATATAN`; AKTIF is Y/N (blank = Y).
- **Blockers** (the row is never published): `kode_kosong`, `kode_ganda` (several SKUs
  split by `/`), `kode_duplikat` (seen earlier in the file), `merk_kosong`,
  `kategori_tidak_terdeteksi`, `qty_ctn_ganda` ("18 / 10", "26-22-55"),
  `qty_ctn_tidak_masuk_akal` (above 1000), `harga_tidak_valid`, `harga_nol`.
  **Notes** (published, annotated in CATATAN): `kode_tidak_standar` (not
  `^[A-Z0-9][A-Z0-9\-.]{1,58}$`), `merk_tidak_dikenal`, `kategori_tidak_dikenal`,
  `qty_ctn_kosong` (uses 1), `qty_ctn_bukan_angka` (uses 1, hints SET),
  `satuan_tidak_dikenal` (uses PCS). Prices parse with either separator convention
  ("Rp 1.250.000", "1,250,000").
- **Diff** against the version in force today: `sku_baru`, `harga_berubah` (old → new,
  share in basis points, the 25 biggest moves), `tidak_berubah`, `tidak_ada_di_file`
  (counted), `error`. **Brake**: more than 20 % of comparable prices changed, or any
  single price moved more than 50 %; the reasons name the numbers.
- **Publish** (status parsed only; a tripped brake needs a typed acknowledgement): one
  transaction creates the version, upserts items from the non-blocker rows (a new item is
  numbered by its KODE, named by DESCRIPTION, with its brand and category looked up or
  created, PCS or SET as base unit, a CTN unit at the carton ratio when above 1; an
  existing item's descriptive fields follow the file and its active flag is left alone),
  writes the version's items, carries forward the current version's items missing from
  the file unchanged — or inactive only when the import is a full replacement — marks
  earlier published versions superseded, caches `items.sell_price`, records the approval
  on the import and an audit entry. Discard keeps the file.
- **Export**: an .xlsx of the current version in the canonical columns.

## 8. Screen **Price List** (Inventory group, work)

Imports with their status, counts and version; Upload, Export; per import a view with
the diff buckets, the biggest moves, the blockers and notes, and Publish or Discard.
Rights: Administrator and Inventory in full; Marketing and Sales read.

## Base edits

`app/Domain/Sales/Contracts/Prices.php` and `app/Domain/Sales/ListPrices.php` (new),
`app/Providers/AppServiceProvider.php` (the binding), `app/Filament/Support/SalesLinesTab.php`
and `app/Domain/Sales/SellingPriceGuard.php` (resolve through the seam), the Price
Categories form (one field), the Items form (three read-only fields), `lang/id.json`.

## Tests

`tests/Feature/Client/`: `PriceResolutionTest` (each step, quantity breaks, dates,
drafts, unpriced), `PriceListParserTest` (the old system's parser cases on workbooks built
in the test), `PriceListImportTest` (parse, duplicate, re-parse, diff buckets, brake,
publish, carry-forward, full replacement, audit, export round trip),
`PriceSnapshotTest`, `PriceListScreensTest`.
