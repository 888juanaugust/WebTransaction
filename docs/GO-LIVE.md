# Go-live — urutan cutover

Urutan ini **sudah digladi**: database kosong → migrasi → pemilik pertama →
workbook pemasok sungguhan → cadangan → latihan pemulihan, dijalankan penuh
pada 2026-08-29. Setiap langkah di bawah adalah langkah yang benar-benar
dieksekusi, dengan jebakannya dicatat. Server disiapkan menurut `docs/DEPLOY.md`
(provision.sh + deploy.sh); dokumen ini mulai **setelah** aplikasi terpasang
dan `.env` produksi terisi.

Prasyarat sebelum hari-H: UAT lulus (`docs/UAT.md`), DNS mengarah ke VPS,
`APP_ENV=production`, `APP_URL` benar (tautan email reset/undangan dibangun
darinya), mail terkonfigurasi (undangan portal dan reset sandi butuh mail
yang benar-benar terkirim).

---

## 1. Skema dan pemilik pertama

```
php artisan migrate --force
php artisan launch:owner
```

- Migrasi dari nol membuat satu wilayah bawaan — tidak perlu seeder apa pun.
  **Jangan jalankan `db:seed` di produksi**: itu membuat akun demo
  `@example.test` bersandi `password`, persis yang ditandai merah oleh
  `launch:check`.
- `launch:owner` menanyakan nama, email, dan kata sandi (min. 12 karakter,
  diketik tersembunyi — sengaja tidak bisa lewat opsi agar tidak tercatat di
  riwayat shell). Perintah ini **hanya bekerja selama belum ada Pemilik
  aktif**; sesudahnya ia menolak dan akun berikutnya dibuat dari layar Staf,
  teraudit atas nama orangnya.

## 2. Kunci cadangan — sebelum ada data yang sayang hilang

```
php artisan backup:key        # salin ke .env sebagai BACKUP_ENCRYPTION_KEY
php artisan config:cache
```

`backup:run` **menolak berjalan tanpa kunci** (tergladi: itu kegagalan
pertama yang kami temui). Simpan kunci di tempat yang BUKAN tujuan cadangan —
kunci yang duduk di samping berkasnya bukan enkripsi. Kehilangan kunci =
kehilangan semua cadangan; tidak ada pemulihan.

## 3. Pemilik masuk dan mengisi identitas

Masuk di `/admin`, lalu:

1. **Pengaturan → Pengaturan perusahaan** — rekening bank (yang tercetak di
   faktur!), identitas, NPWP penjual. Nilai tersimpan menimpa `.env`.
2. **Pengaturan → Staf** — satu akun per orang dengan perannya. Pemisahan
   tugas hidup dari sini: yang menyetujui kredit ≠ yang mengonfirmasi uang.
3. `config/perusahaan.php` — daftar mitra masih nama contoh; ganti atau
   kosongkan (menyebut perusahaan lain di situs publik adalah klaim).

## 4. Harga perdana: workbook pemasok lewat jalurnya sendiri

Layar **Impor harga** → unggah `PL_JAVA_IMPORT.xlsx` (mode workbook pemasok)
→ tinjau selisih → terbitkan.

Hasil gladi dengan berkas sungguhan: **1.402 KODE terbit, 55 baris tertahan
di antrean tinjau** (KODE ganda, KODE bercabang `/`, harga bukan angka —
persis pemblokir yang didesain tertahan). Baris tertahan dibereskan belakangan
satu-satu; jangan menahan go-live untuk 55 baris itu. Rem pengaman diam pada
impor perdana — belum ada harga lama untuk dibandingkan — dan baru bekerja
mulai impor kedua.

Tanpa versi harga terbit, katalog kosong dan tak satu order pun bisa dihargai.

## 4b. Saldo awal: piutang dan hutang dari pembukuan lama

Pelanggan sudah punya hutang, pemasok sudah punya piutang, sebelum sistem ini
ada. Bawa masuk lewat **Keuangan → Impor saldo awal piutang** dan
**Pembelian → Impor saldo awal hutang**: satu baris satu dokumen yang masih
terbuka, dengan **sisa** yang masih terhutang hari ini (bukan nilai
aslinya), memakai nomor dari pembukuan lama. Pelanggan dan pemasoknya harus
sudah ada — impor pelanggan dulu.

Yang terjadi: setiap baris jadi faktur/tagihan terbuka yang menua dari tanggal
aslinya dan dilunasi lewat jalur biasa, dibukukan lawan `3-8000 Saldo Awal
Konversi` — bukan Penjualan, bukan Persediaan, bukan PPN. Tidak ikut komisi,
tidak ikut ekspor faktur pajak. Cocokkan totalnya dengan neraca lama sebelum
lanjut: Piutang Usaha di Neraca saldo harus sama dengan jumlah berkasnya.

Akun staf juga bisa dibawa sekaligus: **Pengaturan → Impor pengguna**, satu
baris satu orang. Baris tanpa KATA_SANDI dibuat dengan sandi acak — atur dari
layar Staf sebelum orangnya masuk.

## 5. Cadangan pertama, lalu latihan memulihkannya

```
php artisan backup:run
php artisan backup:restore --into=webtransaction_latihan
```

Gladi kami: 2 MiB database + 7 MiB berkas, terenkripsi, dibaca balik dan
terverifikasi; pemulihan ke database latihan mengembalikan 1.402 produk utuh
tanpa menyentuh database hidup. Cadangan yang belum pernah dipulihkan adalah
harapan, bukan cadangan — item checklist "pemulihan sudah dilatih" ditandatangani
dari latihan ini, dengan tanggalnya.

Penjadwal (cron `schedule:run`) sudah menjalankan `backup:run` tiap 02:15;
pastikan cron hidup lewat widget **Kesehatan sistem** atau `ops:check`.

## 6. Checklist terakhir

```
php artisan launch:check
```

Exit 0 hanya bila semua lulus. (Catatan skrip: `launch:check | head` melaporkan
exit `head`, bukan checklist — jangan pipa bila membaca kode keluarnya.)
Yang tersisa setelah langkah 1–5 tinggal dunia nyata: PSE Lingkup Privat,
KBLI, pengacara membaca halaman legal, akuntan memastikan format faktur,
denda/batas klaim diputuskan — masing-masing dinyatakan di layar
**Kesiapan peluncuran** dengan nama dan bukti.

## 7. Order sungguhan pertama, lalu pilot

- Satu order nyata dijalankan staf dari ujung ke ujung (order → setujui →
  faktur → bayar → kirim → selesai) — checklist menandainya otomatis.
  Ini tahap 1 urutan pembangunan: **staf memakai sistem sebelum satu pun
  pembeli masuk**.
- Baru kemudian pelanggan pilot di-onboard menurut `docs/PILOT.md`, dari
  layar **Onboarding pelanggan**.

## Kalau harus mundur

- Aplikasi rusak setelah deploy: `git checkout` tag sebelumnya di server,
  `bash deploy/deploy.sh` — migrasi bersifat aditif sehingga skema lama tetap
  jalan di atasnya.
- Data rusak: pulihkan cadangan terakhir **ke database latihan dulu**
  (`--into=…`), periksa isinya, baru putuskan memulihkan ke database hidup.
  Jangan pernah "membetulkan" buku stok atau pembayaran lewat SQL — keduanya
  append-only; koreksi adalah entri pembalik dari layarnya.
