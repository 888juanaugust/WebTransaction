# ACCURATE parity matrix

The definition of done for the ACCURATE-parity programme: WebTransaction
matches ACCURATE Online feature for feature first, and the owner modifies it
after. Decided with the owner on 2026-10-03 (see CLAUDE.md, *ACCURATE parity*).

**How to read a row:** one ACCURATE menu, document, setting or report.
**Status** is what WebTransaction does today:

| Status | Meaning |
|---|---|
| BUILT | Does what ACCURATE does |
| PARTIAL | Exists, but fields, flows or options are missing |
| MISSING | Not there |
| DIFFERS | Exists, but works differently on purpose. Closes as BUILT, or stays DIFFERS behind a **toggle** the owner can switch |
| EXTRA | WebTransaction has it and ACCURATE does not. Kept, behind a toggle |

**Source:** `awal` means seeded from the codebase inventory and general
knowledge of ACCURATE, before the scan. `scan` means confirmed against the
owner's own ACCURATE (`tools/accurate-scan`, output in `docs/accurate/`). Every
`awal` row is re-checked when the scan lands. The scan will also add rows,
especially field-level ones.

**Phase** refers to the programme plan: 1 settings · 2 cabang as a tag ·
3 hak akses · 4 posting engine · 5 HPP recalculation · 6 documents onto the
engine · 7 GL · 8 numbering & approval · 9 inventory master · 10 tax codes ·
11 purchasing · 12 sales · 13 cash/bank & assets · 14 printing · 15 reports ·
16 manufacturing · 17 serial/batch · 18 ACCURATE importers · 19 UAT.

**Done** = every row BUILT, or DIFFERS/EXTRA with its toggle. The owner signs
this file off before Phase 2 starts, and again at Phase 19.

---

## Penjualan — sales

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| S-01 | Penawaran Penjualan | DIFFERS | `app/Domain/Quotes/QuotationFlow.php`, `Filament/Resources/Quotations` | 12 | — | Price comes only from the resolver, no typed price or discount. No edit page, no delete. One quote becomes one order | awal |
| S-02 | Pesanan Penjualan | PARTIAL | `orders` + `app/Domain/Orders/OrderStateMachine.php` | 12 | PersetujuanMarketingWajib, ReservasiStok, PecahGudang | No partial delivery, no "close order", no transaction date, no manual price or discount. Editable only as draft | awal |
| S-03 | Pengiriman Pesanan | MISSING | Surat jalan is a print of the order (`SuratJalanController`) | 12 | — | No delivery table or number. No partial or multiple deliveries per order | awal |
| S-04 | Faktur Penjualan | PARTIAL | `app/Domain/Billing/InvoiceIssuer.php` | 12 | TagihSebelumKirim | No `invoice_lines` of its own. Issued **before** delivery. No direct invoice, no invoice from several deliveries. No back-dating. No line or header discount, no biaya lain. PPN always exclusive | awal |
| S-05 | Edit / hapus faktur yang sudah tercatat | DIFFERS | Refused everywhere (trigger, resource, issuer) | 4, 6, 12 | — | ACCURATE allows both, subject to rights and the closed-period lock | awal |
| S-06 | Uang Muka Penjualan (faktur uang muka) | PARTIAL | `app/Domain/Billing/CustomerDepositRegister.php` | 12 | — | No PPN or faktur on the down payment. No edit; only refund | awal |
| S-07 | Penerimaan Penjualan | PARTIAL | `app/Domain/Payments/PaymentLedger.php`, `Pages/TerimaPembayaran.php` | 6, 12 | — | Pays many invoices at once. No document number, no diskon pelunasan, no write-off. Correction only by reversal | awal |
| S-08 | Retur Penjualan | DIFFERS | `app/Domain/Billing/CreditNoteIssuer.php` / `CreditNotePoster.php` | 6, 12 | PemisahanTugas | Must reference an invoice. Inventori posts it, never the person who filed it. Final once posted | awal |
| S-09 | Pelanggan | PARTIAL | `app/Models/Company.php`, `Resources/Companies` | 12 | — | One shipping address, no billing address. No discount category, default discount, tax-inclusive default or per-customer currency | awal |
| S-10 | Kategori Pelanggan | DIFFERS | `companies.jenis_usaha` (bengkel / toko / distributor) | 12 | — | — | awal |
| S-11 | Penjual (salesman) | DIFFERS | `companies.sales_user_id`, one per customer | 12 | — | ACCURATE puts the salesman on the document | awal |
| S-12 | Level / kategori harga jual | PARTIAL | `price_tiers`, `PriceResolver` | 12 | HargaHanyaDariResolver | No screen for tiers | awal |
| S-13 | Penyesuaian harga / diskon jual | PARTIAL | `company_price_overrides`, tier items | 12 | — | No screen. Discount is derived, never typed | awal |
| S-14 | Syarat Pembayaran | PARTIAL | `payment_terms_days` (an integer) | 12 | — | No early-payment discount terms (2/10 n/30) | awal |
| S-15 | Pengiriman / ekspedisi (shipping method) | MISSING | — | 12 | — | — | awal |
| S-16 | Batas kredit | DIFFERS | `app/Domain/Credit/CreditChecker.php`, `DebtAging.php` | 12 | BekuKredit, PeringatanPiutang | 120-day notice and 150-day freeze are WebTransaction rules. Both switches and both day counts are live (Phase 1) | awal |

## Pembelian — purchasing

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| P-01 | Permintaan Barang | MISSING | Closest: reorder advice drafts a PO (`ReorderAdvisor`) | 11 | — | — | awal |
| P-02 | Pesanan Pembelian | PARTIAL | `app/Domain/Purchasing/PurchaseOrderFlow.php` | 11 | — | No line discount or tax code. A receipt cannot pull from a PO in the UI | awal |
| P-03 | Uang Muka Pembelian | MISSING | — | 11 | — | — | awal |
| P-04 | Penerimaan Barang | BUILT | `GoodsReceiptPoster` | 6, 11 | — | Final once posted. One warehouse per receipt | awal |
| P-05 | Faktur Pembelian | PARTIAL | `SupplierBillPoster` | 11 | — | Lines picked by hand from receipts. A direct bill does not move stock. Discount never written. PPN always added | awal |
| P-06 | Pembayaran Pembelian | PARTIAL | `SupplierLedger`, `Pages/BayarPemasok.php` | 6, 11 | — | No payment discount, no document number | awal |
| P-07 | Retur Pembelian | BUILT | `PurchaseReturnIssuer` / `PurchaseReturnPoster` | 6, 11 | — | Always against one receipt | awal |
| P-08 | Pemasok | PARTIAL | `app/Models/Supplier.php` | 11 | — | Terms not on the form. No PKP flag, tax defaults, bank details or extra contacts | awal |
| P-09 | Biaya lain on purchases | DIFFERS | Landed cost (`LandedCostAllocator`) | 11 | — | ACCURATE: biaya lain to any account on the bill | awal |
| P-10 | Harga beli pemasok | MISSING | Last cost only (`product_costs.last_cost_rupiah`) | 11 | — | — | awal |
| P-11 | Nota kredit pemasok | EXTRA | `SupplierCreditNoteIssuer` | 11 | — | Check against the scan | awal |

## Persediaan — inventory

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| I-01 | Barang & Jasa | PARTIAL | `products`, `Resources/Products` | 9 | PipelineImporHarga | Items enter only by import | awal |
| I-02 | Jenis barang (persediaan, non-persediaan, jasa, grup) | MISSING | Every product is stocked | 9 | — | — | awal |
| I-03 | Kategori barang with default accounts | MISSING | `kategori` and `merk` are strings | 9 | — | — | awal |
| I-04 | Satuan (multi-unit, conversions, per-unit price) | PARTIAL | Base unit + `qty_per_ctn` (`app/Domain/Uom/Unit.php`) | 9 | — | — | awal |
| I-05 | Gudang | PARTIAL | `warehouses`, no screen | 9 | — | — | awal |
| I-06 | Penyesuaian Persediaan | MISSING | Only an opname variance | 9 | — | — | awal |
| I-07 | Pemindahan Barang | BUILT | `StockTransferPoster` | 2, 9 | — | Same cabang only, one step. Check transit in the scan | awal |
| I-08 | Stok Opname | DIFFERS | `StockOpnameSheet` / `StockOpnamePoster` | 9 | PemisahanTugas | Counter ≠ approver is a WebTransaction rule | awal |
| I-09 | Saldo awal persediaan | MISSING | Demo uses a goods receipt | 7, 9 | — | — | awal |
| I-10 | HPP (average) + recalculation after back-dated edits | DIFFERS | Moving average frozen in insert order (`InventoryValuation`) | 5 | — | Movements carry no document date | awal |
| I-11 | Minimum stock | DIFFERS | Computed reorder point (`ReorderAdvisor`) | 9 | — | — | awal |
| I-12 | Nomor seri / produksi, kedaluwarsa | MISSING | — | 17 | — | — | awal |
| I-13 | Barang grup / rakitan | MISSING | — | 9, 16 | — | — | awal |
| I-14 | Stok minus allowed / refused | DIFFERS | Always refused | 4 | izinkan_stok_minus | — | awal |

## Manufaktur

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| M-01 | Formula Produksi (BOM) | MISSING | — | 16 | — | — | awal |
| M-02 | Perintah Kerja | MISSING | — | 16 | — | — | awal |
| M-03 | Pengambilan Bahan Baku | MISSING | — | 16 | — | Into a WIP account | awal |
| M-04 | Penyelesaian Barang Jadi | MISSING | — | 16 | — | Cost = inputs + overhead (Phase 5 `sum_of_inputs`) | awal |
| M-05 | Biaya produksi / overhead | MISSING | — | 16 | — | — | awal |
| M-06 | Pekerjaan Pesanan (job costing) | MISSING | — | 16 | — | Confirm in the scan which of M-02 or M-06 the edition offers | awal |

## Kas & Bank

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| K-01 | Several kas and bank accounts | PARTIAL | `app/Domain/Banking/BankAccounts.php` | 13 | — | Many banks, but one Kas | awal |
| K-02 | Pembayaran (multi-line, any account) | PARTIAL | `ExpenseRecorder`: one line, expense accounts only | 13 | — | — | awal |
| K-03 | Penerimaan (other cash in) | MISSING | Only as a reconciliation item | 13 | — | — | awal |
| K-04 | Transfer Bank | MISSING | — | 13 | — | — | awal |
| K-05 | Rekonsiliasi Bank | BUILT | `BankReconciler` | 4 | — | Stricter than ACCURATE. Check whether a reconciled transaction stays editable | awal |
| K-06 | Giro | BUILT | `app/Domain/Giro/GiroRegister.php` | 6 | — | Check against the scan | awal |

## Buku Besar — general ledger

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| G-01 | Daftar Akun (editable) | MISSING | Hard-coded `app/Domain/Accounting/AccountCode.php` | 7 | — | — | awal |
| G-02 | Tipe akun (Kas & Bank, Piutang, Persediaan, …) | DIFFERS | 5 classes in `AccountType` | 7 | — | — | awal |
| G-03 | Jurnal Umum | MISSING | `Ledger::postManual` exists, no screen | 7 | — | — | awal |
| G-04 | Saldo awal akun | PARTIAL | AR/AP importers only | 7 | — | — | awal |
| G-05 | Akun bawaan (default-account preferences) | MISSING | Hard-coded in `DocumentPoster` | 7 | — | — | awal |
| G-06 | Departemen | MISSING | — | 7 | — | — | awal |
| G-07 | Proyek | MISSING | — | 7 | — | — | awal |
| G-08 | Anggaran | MISSING | — | 7 | — | — | awal |
| G-09 | Tutup buku / periode | DIFFERS | `PeriodCloser`, per region | 2, 7 | — | Laba ditahan by closing journal: check what ACCURATE does | awal |
| G-10 | Cabang | DIFFERS | Separate books per region | 2 | — | Becomes a tag in one set of books | awal |
| G-11 | Edit / hapus transaksi tercatat; tanggal mundur | DIFFERS | Append-only by DB trigger, dated "now" | 4–6 | — | — | awal |

## Aset Tetap — fixed assets

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| A-01 | Aset tetap | PARTIAL | `app/Domain/Assets/FixedAssetRegister.php` | 13 | — | Always paid by Kas/Bank (no credit purchase), no edit | awal |
| A-02 | Kategori aset with accounts | PARTIAL | Labels only, two accounts for everything | 13 | — | — | awal |
| A-03 | Metode penyusutan | PARTIAL | Straight line only | 13 | — | — | awal |
| A-04 | Disposisi aset | BUILT | `FixedAssetRegister` | 13 | — | — | awal |
| A-05 | Perubahan / revaluasi aset | MISSING | — | 13 | — | — | awal |

## Perpajakan — tax

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| T-01 | Kode pajak per line, termasuk/belum termasuk pajak | DIFFERS | Fixed PPN, DPP 11/12, exclusive (`TaxCalculator`) | 10 | — | **The accountant confirms before merge** | awal |
| T-02 | e-Faktur / Coretax export | BUILT | `CoretaxXmlWriter` | 10 | EksporCoretax | — | awal |
| T-03 | PPh (pemotongan) | MISSING | — | 10 | — | Confirm in the scan whether it is used | awal |

## Pengaturan — settings and cross-cutting

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| X-01 | Info perusahaan | BUILT | `Pages/PengaturanPerusahaanPage.php` | — | — | — | awal |
| X-02 | Preferensi | PARTIAL | `app/Domain/Pengaturan/Preferensi.php`, `Fitur.php`, `Pages/Preferensi.php` | 1, then each phase | — | Store, switches and screen built (Phase 1). Each phase adds the preferences its ACCURATE screens have | awal |
| X-03 | Pengguna & hak akses (per menu: lihat/tambah/ubah/hapus/cetak) | DIFFERS | 6 fixed roles (`app/Domain/Access/Role.php`) | 3 | — | — | awal |
| X-04 | Akses cabang / gudang per pengguna | DIFFERS | One region per user | 2, 3 | — | — | awal |
| X-05 | Persetujuan (approval rules) | DIFFERS | Hard-coded per document | 8 | PersetujuanMarketingWajib | — | awal |
| X-06 | Penomoran (number formats) | DIFFERS | Fixed `PREFIX-CABANG-YYYYMM-NNNN` | 8 | — | — | awal |
| X-07 | Desain cetakan | MISSING | Fixed HTML print views | 14 | — | — | awal |
| X-08 | Riwayat / audit | DIFFERS | Append-only audit log, Owner only | 4 | — | Adds document revisions | awal |
| X-09 | Impor data (Excel templates) | PARTIAL | Customers (ACCURATE template), products, AR/AP opening balances | 18 | — | — | awal |
| X-10 | Data kustom (custom fields) | MISSING | — | 15 | — | Confirm in the scan | awal |
| X-11 | Mata uang asing | — | — | — | — | **Out of scope** (owner, 2026-10-03) | awal |

## Laporan — reports

| # | ACCURATE | Status | WebTransaction today | Phase | Toggle | Notes | Source |
|---|---|---|---|---|---|---|---|
| R-01 | Neraca | PARTIAL | `Pages/Akuntansi/Neraca` | 7 | — | No comparative columns, no current/non-current split | awal |
| R-02 | Laba Rugi | PARTIAL | `Pages/Akuntansi/LabaRugi` | 7 | — | Three sections. No per department or project | awal |
| R-03 | Neraca Saldo | BUILT | `Pages/Akuntansi/NeracaSaldo` | 15 | — | — | awal |
| R-04 | Buku Besar (running balance per account) | MISSING | Journal register only | 7 | — | — | awal |
| R-05 | Arus Kas | MISSING | — | 7 | — | — | awal |
| R-06 | Perubahan Modal | MISSING | — | 7 | — | — | awal |
| R-07 | Rincian penjualan per pelanggan / barang / penjual | PARTIAL | `Pages/Laporan/Penjualan` | 15 | — | — | awal |
| R-08 | Pesanan belum dikirim / belum ditagih | MISSING | — | 12 | — | — | awal |
| R-09 | Umur piutang | BUILT | `Pages/Laporan/UmurPiutang` | — | — | — | awal |
| R-10 | Rincian pembelian per pemasok / barang | MISSING | — | 11 | — | — | awal |
| R-11 | PO belum diterima / penerimaan belum ditagih | MISSING | — | 11 | — | — | awal |
| R-12 | Umur hutang | BUILT | `Pages/Laporan/UmurHutang` | — | — | — | awal |
| R-13 | Kartu stok / mutasi persediaan | MISSING | Raw movement list in Penjelajah | 9 | — | — | awal |
| R-14 | Nilai persediaan per gudang | MISSING | — | 9 | — | — | awal |
| R-15 | Mutasi kas & bank | MISSING | — | 13 | — | — | awal |
| R-16 | Jadwal penyusutan | MISSING | — | 13 | — | — | awal |
| R-17 | Export of any report to Excel and PDF | PARTIAL | CSV only | 7, 14 | — | — | awal |

## WebTransaction extras — kept, each behind a switch

All default **on**, matching today's behaviour. The owner decides in the modify phase. **Live** means the switch is on the Preferensi screen now; the rest arrive with their phase (`Fitur::berlakuMulaiFase()`).

| Extra | Switch (Phase 1) |
|---|---|
| Buyer portal (`/portal`) | `PortalPembeli` |
| Public site | `SitusPublik` |
| Every order waits for its marketing's approval | `PersetujuanMarketingWajib` (becomes a seeded approval rule in Phase 8) |
| 120-day notice | `PeringatanPiutang` — **live** |
| 150-day freeze | `BekuKredit` — **live** |
| Split one order across warehouses | `PecahGudang` |
| Stock reserved at confirmation | `ReservasiStok` |
| Invoice issued before shipping | `TagihSebelumKirim` |
| Commission on settled invoices | `Komisi` — **live** |
| Store visits | `KunjunganToko` — **live** |
| Coretax XML export | `EksporCoretax` — **live** |
| Price-list import pipeline (staging, diff, brake) | `PipelineImporHarga` |
| Selling price only from the resolver | `HargaHanyaDariResolver` |
| Two-key rules (filer ≠ verifier, counter ≠ approver, …) | `PemisahanTugas` (Phase 3) |

---

## Questions the scan must answer

Later phases depend on these answers. Each one goes into the row it affects, with Source `scan`.

1. Is HPP averaged per item company-wide, or per gudang? Does the edition offer FIFO? (I-10, Phase 5)
2. In what order are same-day transactions costed: input order, or inbound before outbound? (Phase 5)
3. Which accounts does a Pengiriman Pesanan journal use (Barang Terkirim?), and which does the invoice that follows use? (S-03/S-04)
4. Can a transaction be edited after its bank line is reconciled? (K-05, Phase 4 blockers)
5. Does Pemindahan Barang have an in-transit step? (I-07)
6. Which depreciation methods exist? (A-03)
7. Is laba ditahan a closing journal, or computed? (G-09)
8. Which special rights does Hak Akses list beyond lihat/tambah/ubah/hapus/cetak? (X-03)
9. Which numbering tokens and reset periods does Penomoran offer? (X-06)
10. Which manufacturing documents does this edition have? (M-01–M-06)
