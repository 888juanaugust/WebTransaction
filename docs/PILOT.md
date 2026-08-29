# Pilot pelanggan — panduan jalan

Tahap 3 urutan pembangunan: **3–4 pelanggan bersahabat** memakai portal dengan
data sungguhan selama **dua minggu**. Pilot bukan demo — uangnya asli, stoknya
asli, piutangnya asli. Yang diuji bukan perangkat lunaknya saja, tetapi apakah
alur kerja staf + pembeli berjalan tanpa ada yang menelepon developer.

Prasyarat: UAT lulus (`docs/UAT.md`), `php artisan launch:check` bersih dari
sisi perangkat lunak, dan Pengaturan perusahaan terisi (rekening!).

---

## 1. Memilih pelanggan pilot

Pilih 3–4 yang:

- **sudah lama berlangganan** dan tidak akan pindah gara-gara satu hari kikuk;
- **memesan rutin** — pesan ulang mingguan-lah yang portal ini bagus melakukannya;
- punya **satu orang yang jelas** yang memegang HP/komputer dan mau dihubungi;
- kalau bisa, campur: satu bengkel kecil, satu toko, satu distributor —
  ketiganya memakai layar yang sama dengan pola yang berbeda.

Jangan pilih pelanggan yang piutangnya sedang bermasalah: pilot bukan alat
penagihan, dan pembekuan 150 hari akan menyapa mereka di layar pertama.

## 2. Meng-onboard satu pelanggan — urutannya

Semua dari layar **Onboarding pelanggan** di panel admin. Layar itu daftar
kerja: sembilan langkah per pelanggan, dihitung ulang dari keadaan nyata
setiap dibuka — tidak ada centang manual, jadi tidak ada centang basi.

1. **Data kontak & pajak** — layar Pelanggan: alamat kirim, kota, telepon,
   email; NPWP + nama wajib pajak + alamat pajak bila PKP (tanpa itu boleh
   jalan, tapi tanpa faktur pajak).
2. **Setujui akun** — antrean "Akun menunggu persetujuan" di dasbor. Menyetujui
   sekaligus menetapkan **limit kredit** dan termin; limit nol berarti order
   pertama pasti tertolak.
3. **Pasang tim** — Owner memasang satu Sales (sewilayah) + satu Marketing
   lewat layar Pelanggan. Tanpa marketing, tidak ada kursi yang bisa
   menyetujui ordernya.
4. **Pilih tingkat harga** — tanpa tier, katalog portal tidak menampilkan harga.
5. **Buat akun portal** — layar Pelanggan → *Akun portal pelanggan* → Tambah.
   **Tidak ada kata sandi yang diketik.** Pembeli menerima email undangan
   berisi tautan atur-kata-sandi (berlaku 60 menit, sekali pakai). Tersangkut
   di spam → tombol *Kirim undangan* mengirim ulang; tautan lama hangus.
6. **Dampingi masuk pertama** — telepon atau kunjungan sales. Langkah "Pembeli
   sudah masuk" menghijau sendiri begitu mereka berhasil.
7. **Order pertama** — dampingi pesan ulang / keranjang pertama sampai
   `submitted`, disetujui marketing, dan barangnya benar-benar sampai.
   Langkah terakhir menghijau, pelanggan resmi onboard.

## 3. Selama dua minggu

- **Satu nomor WhatsApp** sebagai jalur eskalasi, dipegang orang yang bisa
  membuka panel admin. Semua keluhan lewat situ — bukan lewat sales
  masing-masing — supaya polanya kelihatan.
- Tiap pagi, orang itu membuka: **Onboarding pelanggan** (ada langkah yang
  un-tick semalam?), dasbor antrean (order menggantung? pembayaran belum
  cocok?), dan widget **Kesehatan sistem** / `php artisan ops:check`.
- Catat setiap telepon: apa yang mereka coba, di layar mana macet, berapa
  menit sampai jalan lagi. Daftar ini adalah backlog perbaikan tahap 5.
- Jangan menambah pelanggan pilot di minggu pertama. Minggu kedua boleh
  satu-dua bila tiga yang pertama lancar.

## 4. Kriteria lulus pilot

Pilot dinyatakan berhasil bila, dalam dua minggu:

- setiap pelanggan pilot menyelesaikan **≥2 order tanpa didampingi** (pesan
  ulang dihitung — justru itu intinya);
- **tidak ada order yang nyangkut** di status yang tidak bisa dijelaskan;
- pembayaran mereka tercatat dan cocok oleh Finance lewat alur normal;
- tidak ada masalah yang butuh developer untuk memulihkan data.

Gagal salah satu → perbaiki, ulangi dua minggu dengan pelanggan yang sama.
Lulus → lanjut tahap 4: situs publik, kebijakan privasi, PSE, peluncuran.

## 5. Kalau ada yang salah

- **Pembeli tidak bisa masuk**: cek akun aktif + perusahaan aktif (keduanya
  syarat login), lalu kirim ulang undangan. Kata sandi manual lewat layar
  akun portal adalah jalan terakhir, bukan kebiasaan.
- **Harga tidak muncul / salah**: cek tier pelanggan dan versi price list —
  jangan pernah mengubah harga langsung; terbitkan versi baru.
- **Order tertolak kredit**: itu sistem bekerja, bukan rusak. Limit dinaikkan
  hanya oleh yang berwenang, dan tercatat di log audit.
- **Data terlanjur kacau**: berhenti, jangan "membetulkan" lewat SQL. Buku
  stok dan pembayaran append-only — koreksi adalah entri pembalik, dan ada
  layarnya masing-masing.
