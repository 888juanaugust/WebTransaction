# Uji Terima Pengguna (UAT)

Skenario per peran, dijalankan oleh orang yang akan memakai sistem — bukan
oleh yang membangunnya. Setiap skenario punya langkah, hasil yang diharapkan,
dan kolom Lulus/Gagal. **Sistem diterima ketika setiap baris Lulus, diparaf
oleh orang yang menjalankannya.**

Jalankan pada instalasi berisi data demo (`docs/DEMO.md §1`), atau — untuk
pilot — data sungguhan yang dimasukkan staf sendiri. Setiap kegagalan dicatat
dengan: skenario, langkah ke berapa, apa yang terjadi, tangkapan layar.

Login demo ada di `docs/DEMO.md §2`. Semua sandi `password`.

---

## A. Owner — penyiapan tanpa terminal

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| A1 | Masuk sebagai Owner → **Pengaturan → Pengaturan perusahaan** | Form: Rekening, Identitas, Pajak penjual, Nilai komersial | ☐ |
| A2 | Isi rekening perusahaan (bank, nomor, atas nama) → **Simpan** | Notifikasi "Pengaturan tersimpan" | ☐ |
| A3 | Buka faktur mana pun → **Cetak faktur** | Blok Pembayaran menampilkan rekening yang barusan diketik | ☐ |
| A4 | **Pengaturan → Kesiapan peluncuran** | Baris "Rekening perusahaan sudah diisi" hijau dengan sendirinya | ☐ |
| A5 | **Pengaturan → Log audit** | Ada baris "Pengaturan perusahaan diubah" dengan nilai lama → baru, atas nama Owner | ☐ |
| A6 | Isi identitas perusahaan + pajak penjual, lalu buka situs publik `/kontak` | Alamat/telepon baru tampil | ☐ |
| A7 | Coba buka Pengaturan perusahaan sebagai Finance | Ditolak (403) | ☐ |
| A8 | **Staf**: buat satu akun Sales baru di wilayah, lalu nonaktifkan | Akun bisa masuk sebelum, tidak bisa sesudah | ☐ |

## B. Sales — hari kerja di lapangan

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| B1 | Masuk sebagai Sales → **Order baru** untuk pelanggan binaan, 2 barang | Order tersimpan `draft`, harga tampil, ajukan → `submitted` | ☐ |
| B2 | Coba **Setujui** order sendiri | Tidak ada tombol Setujui — sales tidak menyetujui kredit | ☐ |
| B3 | **Kunjungan toko → Buat** dari HP | Kamera belakang terbuka untuk foto; "lokasi terekam ✓"; waktu tidak bisa diketik | ☐ |
| B4 | **Pelanggan saya** → buka satu pelanggan | Riwayat belanja + saran barang yang belum pernah dibeli | ☐ |
| B5 | **Biaya ekspedisi**: klaim bensin/tol dengan catatan | Masuk antrean verifikasi Finance — belum jadi beban di buku | ☐ |
| B6 | **Faktur** pelanggan binaan → **Ajukan pelunasan** (tunai diterima di toko) | Klaim terkirim; faktur belum lunas sampai Finance menyetujui | ☐ |
| B7 | Ajukan **retur** untuk toko binaan dari faktur | Draft nota kredit menunggu verifikasi Inventori | ☐ |

## C. Marketing — kursi persetujuan (global)

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| C1 | Masuk sebagai Marketing | Lencana "Semua wilayah"; tidak ada pemilih wilayah | ☐ |
| C2 | **Order menunggu persetujuan**: baris menampilkan sisa kredit + stok | Kolom Stok "Cukup" / "Tersebar di N gudang" (oranye) / "Stok kurang" (merah) | ☐ |
| C3 | **Setujui** order yang stoknya cukup | "Harga terkunci dan stok dipesan"; order `confirmed` | ☐ |
| C4 | Setujui order yang stoknya **tersebar** di ≥2 gudang | Modal menyebut pecahan per gudang; hasilnya X transaksi, pecahan bernomor wilayah pengirim, induk tetap | ☐ |
| C5 | Setujui order melebihi **limit kredit** | Ditolak dengan pesan limit; order tetap `submitted` | ☐ |
| C6 | **Hapus** order draft yang batal | Terhapus; jejaknya ada di Log audit | ☐ |
| C7 | Coba setujui order pelanggan marketing **lain** | Tidak muncul di antrean / ditolak | ☐ |

## D. Finance — uang masuk dan verifikasi

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| D1 | **Tagihkan** order `confirmed` | Faktur terbit; jatuh tempo +30 hari; blok pembayaran = rekening dari Pengaturan | ☐ |
| D2 | **Catat pembayaran** transfer penuh pada faktur | Faktur `lunas`; order maju sendiri (`paid`/`selesai`) tanpa tombol tambahan | ☐ |
| D3 | **Pembayaran belum cocok**: catat transfer tanpa faktur, lalu cocokkan | Setelah dicocokkan, faktur lunas | ☐ |
| D4 | Verifikasi **pelunasan piutang** yang diajukan Sales/Marketing (B6) | Pembayaran terposting; pengaju ≠ pemverifikasi (dua kunci) | ☐ |
| D5 | Coba verifikasi klaim yang **Finance sendiri** ajukan (minta Owner mengajukan tes) | Ditolak | ☐ |
| D6 | Verifikasi **biaya ekspedisi** (B5) | Menjadi beban di buku besar | ☐ |
| D7 | Coba **ubah harga** di price list | Tidak bisa — bukan wewenang Finance | ☐ |
| D8 | **Laporan → Umur piutang** | Bagan bucket umur di atas tabel; angka = total buku | ☐ |

## E. Inventori — gudang

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| E1 | Masuk sebagai Inventori | Tidak ada layar kredit pelanggan; katalog tanpa harga jual pelanggan | ☐ |
| E2 | **Pengiriman**: proses order `confirmed` → kirim | Stok berkurang di buku stok; surat jalan bisa dicetak, tanpa satu rupiah pun | ☐ |
| E3 | Verifikasi **retur** (B7) | Barang kembali ke stok; nota kredit terposting | ☐ |
| E4 | **Impor harga**: unggah file harga → tinjau selisih → terbitkan | Lima keranjang selisih tampil; rem pengaman muncul bila perubahan >20% | ☐ |
| E5 | **Transfer gudang** antar gudang sewilayah | Stok pindah; nilainya netral di buku | ☐ |

## F. Pembeli — portal

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| F1 | Masuk portal sebagai pembeli | Layar awal: pesan ulang, sisa kredit, bagan belanja | ☐ |
| F2 | **Pesan ulang** order terakhir, ubah satu jumlah, ajukan | Order `submitted` menunggu persetujuan — pembeli tidak bisa menyetujui sendiri | ☐ |
| F3 | **Tagihan** → buka faktur terbuka | "Cara pembayaran" = rekening perusahaan + berita nomor faktur | ☐ |
| F4 | **Cetak faktur** dari portal | Sama persis dengan versi staf | ☐ |
| F5 | **Lupa kata sandi** dari halaman masuk portal | Email tautan reset; sandi baru bisa masuk; tautan tidak bisa dipakai dua kali | ☐ |
| F6 | Coba buka faktur milik **perusahaan lain** (ubah angka di URL) | Ditolak | ☐ |
| F7 | Pembeli yang fakturnya menunggak >150 hari mencoba memesan | Ditolak dengan pesan pembekuan; kembali normal setelah faktur dilunasi | ☐ |

## G. Lintas peran — pemisahan tugas

| # | Langkah | Hasil yang diharapkan | Lulus |
|---|---|---|---|
| G1 | Satu klaim (pelunasan/retur/biaya): pengaju mencoba memverifikasi sendiri | Selalu ditolak, termasuk Owner | ☐ |
| G2 | Yang mengonfirmasi pembayaran mencoba mengubah nilai faktur | Tidak ada jalannya | ☐ |
| G3 | Buka **Log audit** setelah semua skenario | Setiap penimpaan/pembalikan tercatat dengan lama → baru | ☐ |

---

## Penutupan UAT

- Setiap kegagalan dicatat dan diperbaiki, lalu **skenario yang gagal diulang**
  — bukan hanya perbaikannya yang dites.
- Pilot berikutnya (build order tahap 3): 3–4 pelanggan bersahabat memakai
  portal dengan data sungguhan selama dua minggu, dengan jalur eskalasi satu
  nomor WhatsApp. Panduan lengkapnya: `docs/PILOT.md`, dijalankan dari layar
  **Onboarding pelanggan** di panel admin.
- Setelah semua Lulus: jalankan `php artisan launch:check` — sisa item adalah
  urusan dunia nyata (PSE, pengacara, akuntan), bukan perangkat lunak.
