# Diagrams

Use case, order workflow and ERD for the system, read from the code at `0683d20`.
They are not hand-drawn guesses: the ERD is generated from `database/migrations`,
and the use cases and role rights from `app/Domain/Access/Role.php`,
`app/Filament/**` and the scheduler in `app/Providers/AppServiceProvider.php`.

## How to view

- **GitHub** renders `.mmd` content inside a ```` ```mermaid ```` block. Paste a file into
  any Markdown file, or open it in <https://mermaid.live>.
- **VS Code / PhpStorm**: a Mermaid preview plugin opens `.mmd` directly.
- **`use-case.puml`** is the same use-case content in full UML notation (stick-figure
  actors, ovals, system boundary). Render it with PlantUML, for example the VS Code
  PlantUML plugin or <https://www.plantuml.com/plantuml>.

## Files

| File | Shows |
|---|---|
| `use-case.puml` | Every actor and use case in one UML use-case diagram (PlantUML) |
| `use-case-<actor>.mmd` | One small use-case diagram per actor: visitor, buyer, sales, marketing, inventori, gudang, finance, owner, sistem |
| `order-state.mmd` | Order state machine (`OrderStateMachine`), with who may fire each transition |
| `order-to-cash.mmd` | Sequence of one credit sale: submit, approve (split, price snapshot, credit check, reservation), invoice, ship, payment, settlement |
| `erd-sales.mmd` | Customers, staff, regions, carts, quotations, orders, invoices, payments |
| `erd-pricing.mmd` | Products, price list imports and versions, tiers, customer overrides |
| `erd-stock.mmd` | Stock ledger, levels, reservations, transfers, opname, costs |
| `erd-purchasing.mmd` | Suppliers, PO, goods receipts, supplier bills, landed cost, purchase returns, supplier payments, giro |
| `erd-receivables.mmd` | Invoices, payment allocations, credit notes, deposits, giro, debt removals, collection, faktur pajak exports |
| `erd-accounting.mmd` | Chart of accounts, journals, periods, bank reconciliation, expenses, fixed assets |
| `erd-field.mmd` | Store visits, expense claims, commission, targets, audit log, settings |

ERD notes: only key columns are drawn. User columns such as `created_by` and
`posted_by` are left out to keep the picture readable. `sku` (and `kode` on
price tables) points to `products.kode`, which is a string primary key, not a
database foreign key. When migrations change, regenerate rather than edit by hand.

## Role matrix

| Use case | Pembeli | Sales | Marketing | Inventori | Gudang | Finance | Owner | Sistem |
|---|---|---|---|---|---|---|---|---|
| **Katalog dan harga** | | | | | | | | |
| Lihat katalog | ● | ● | ● | ● | ● | ● | ● |  |
| Ubah barang, impor barang |  |  |  | ● |  |  | ● |  |
| Unggah berkas harga, publikasikan versi |  |  |  | ● |  |  | ● |  |
| **Pemesanan** | | | | | | | | |
| Keranjang, ajukan pesanan, pesan ulang | ● |  |  |  |  |  |  |  |
| Buat order untuk pelanggan |  | ● | ● |  |  |  | ● |  |
| Penawaran: buat, kirim, jadikan order |  | ● | ● |  |  |  | ● |  |
| **Persetujuan dan kredit** | | | | | | | | |
| Setujui / tolak / hapus order (hanya marketing pemegang pelanggan) |  |  | ● |  |  |  | ● |  |
| Lihat dan ubah pelanggan, akun portal |  | ● | ● |  |  | ● | ● |  |
| Atur tim sales + marketing pelanggan |  |  |  |  |  |  | ● |  |
| Wawasan pelanggan |  | ● | ● |  |  |  | ● |  |
| **Gudang dan pengiriman** | | | | | | | | |
| Antrian pengiriman, surat jalan, tandai dikirim, selesaikan |  |  |  | ● | ● |  | ● |  |
| Transfer stok |  |  |  | ● |  |  | ● |  |
| Stock opname: buat lembar, isi hitungan |  |  |  | ● |  |  | ● |  |
| Setujui selisih opname (bukan penghitung) |  |  |  |  |  | ● | ● |  |
| Titik pesan ulang, buat draf PO |  |  |  |  |  | ● | ● |  |
| **Pembayaran dan piutang** | | | | | | | | |
| Tagihkan: terbitkan faktur |  | ● | ● |  |  | ● | ● |  |
| Catat / cocokkan pembayaran |  |  |  |  |  | ● | ● |  |
| Tandai lunas dari ledger pembayaran |  |  |  |  |  |  |  | ● |
| Uang muka pelanggan |  |  |  |  |  | ● | ● |  |
| Penagihan: catat kontak dan janji bayar |  | ● | ● |  |  | ● | ● |  |
| Ajukan pelunasan piutang |  | ● | ● |  |  |  | ● |  |
| Verifikasi pelunasan (bukan pengaju) |  |  |  |  |  | ● | ● |  |
| Giro: terima, terbitkan, cair, tolak |  |  |  |  |  | ● | ● |  |
| Lihat tagihan, kredit tersedia, umur tagihan | ● |  |  |  |  |  |  |  |
| **Retur** | | | | | | | | |
| Buat nota kredit / retur |  | ● |  |  |  |  | ● |  |
| Posting retur: barang kembali (bukan pembuat) |  |  |  | ● |  |  | ● |  |
| **Pembelian** | | | | | | | | |
| PO, penerimaan barang, tagihan, bayar pemasok |  |  |  |  |  | ● | ● |  |
| Retur pembelian, nota kredit pemasok, biaya angkut |  |  |  |  |  | ● | ● |  |
| **Akuntansi dan pajak** | | | | | | | | |
| Jurnal, neraca, laba rugi, rekonsiliasi bank |  |  |  |  |  | ● | ● |  |
| Beban, aktiva tetap |  |  |  |  |  | ● | ● |  |
| Tutup buku |  |  |  |  |  | ● | ● |  |
| Buka kembali periode |  |  |  |  |  |  | ● |  |
| Ekspor faktur pajak, catat NSFP |  |  |  |  |  | ● | ● |  |
| **Sales lapangan** | | | | | | | | |
| Catat kunjungan toko, ajukan klaim biaya |  | ● |  |  |  |  |  |  |
| Verifikasi klaim biaya |  |  |  |  |  | ● | ● |  |
| Atur komisi dan target |  |  |  |  |  | ● | ● |  |
| **Laporan dan admin** | | | | | | | | |
| Laporan penjualan, KPI, umur piutang |  | ● | ● |  |  | ● | ● |  |
| Staf, cabang, rekening bank, pengaturan, log audit |  |  |  |  |  |  | ● |  |
| **Otomatis** | | | | | | | | |
| Parse berkas harga, lepas reservasi basi, sapu umur piutang, cek keutuhan buku, backup |  |  |  |  |  |  |  | ● |

Two-key rules: the person who files a pelunasan piutang, a retur or a stock opname
count never verifies it. Sales never approve orders. Only the payment ledger sets
an order to `paid`.
