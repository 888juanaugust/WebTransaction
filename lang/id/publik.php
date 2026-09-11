<?php

declare(strict_types=1);

/*
 * The public site's own words, in Bahasa Indonesia — the default.
 *
 * Every sentence the shopfront's templates print lives here or in its
 * English twin, keyed identically. What the *company* says about itself —
 * tagline, profile, categories, roadmap — is not here: that is content, it
 * belongs to the Owner, and it lives in config/perusahaan.php as bilingual
 * pairs. This file is the chrome around it.
 */
return [
    'nama_bahasa' => 'Bahasa Indonesia',
    'ganti_bahasa' => 'English',
    'ganti_bahasa_label' => 'Switch to English',

    'menu' => [
        'beranda' => 'Beranda',
        'tentang' => 'Tentang Kami',
        'mitra' => 'Mitra',
        'rencana' => 'Rencana',
        'kontak' => 'Kontak',
        'masuk' => 'Masuk',
        'lewati' => 'Langsung ke isi',
        'buka' => 'Buka menu',
        'tutup' => 'Tutup menu',
        'utama' => 'Utama',
    ],

    'kaki' => [
        'halaman' => 'Halaman',
        'hukum' => 'Hukum',
        'privasi' => 'Kebijakan Privasi',
        'syarat' => 'Syarat Penjualan',
        'kontak' => 'Kontak',
        'hak_cipta' => 'Hak cipta dilindungi.',
        'harga_grosir' => 'Harga grosir hanya untuk pelanggan terdaftar.',
    ],

    'beranda' => [
        'judul' => 'Beranda',
        'hero' => 'Suku cadang otomotif grosir, harga per pelanggan.',
        'masuk_akun' => 'Masuk ke akun Anda',
        'hubungi' => 'Hubungi kami',
        'melayani' => 'Kami melayani :siapa, bukan pembeli eceran. Harga grosir hanya untuk pelanggan terdaftar.',
        'melayani_siapa' => 'bengkel, toko sparepart, dan distributor',
        'fakta' => [
            'kategori' => 'Kategori produk',
            'merk' => 'Merk yang dibawa',
            'gudang' => 'Gudang utama',
            'tempo' => 'Tempo kredit standar',
            'tempo_nilai' => '30 hari',
        ],
        'kategori_judul' => 'Kategori produk',
        'kategori_lede' => 'Empat kategori yang paling sering dibutuhkan bengkel, selalu ada stok.',
        'daftar_lengkap' => 'Daftar produk lengkap beserta harganya tersedia untuk pelanggan terdaftar setelah masuk.',
        'merk_judul' => 'Merk yang kami bawa, siap kirim ke seluruh Indonesia',
        'langkah_judul' => 'Jadi pelanggan dalam tiga langkah',
        'langkah_lede' => 'Akun grosir dibuka setelah data usaha Anda diverifikasi. Tidak ada pendaftaran mandiri.',
        'langkah' => [
            ['Kirim data usaha Anda', 'Nama usaha, alamat, dan NPWP lewat WhatsApp atau email. Akun dibuka setelah datanya diverifikasi.'],
            ['Sepakati harga dan limit kredit', 'Tim kami menetapkan tier harga dan limit kredit sesuai skala usaha Anda, dengan syarat pembayaran yang jelas.'],
            ['Pesan lewat portal', 'Katalog dengan harga Anda, pesanan rutin diulang sekali klik, dan faktur kapan pun dibutuhkan.'],
        ],
        'tentang_judul' => 'Tentang perusahaan',
        'tentang_lanjut' => 'Selengkapnya tentang kami',
        'mitra_judul' => 'Mitra',
        'mitra_semua' => 'Lihat semua mitra',
        'sejak' => 'sejak',
        'sudah_pelanggan' => 'Sudah jadi pelanggan?',
        'sudah_pelanggan_lede' => 'Masuk untuk melihat harga Anda, sisa limit kredit, faktur, dan riwayat pesanan.',
    ],

    'promo' => [
        'label' => 'Promo',
        'sebelumnya' => 'Promo sebelumnya',
        'berikutnya' => 'Promo berikutnya',
        'ke' => 'Ke promo :nomor',
        'selengkapnya' => 'Selengkapnya',
    ],

    'tentang' => [
        'judul' => 'Tentang Kami',
        'deskripsi' => 'Profil :nama, distributor grosir suku cadang otomotif.',
        'profil' => 'Profil perusahaan',
        'melayani' => 'Siapa yang kami layani',
        'segmen' => [
            ['Bengkel', 'Kebutuhan perbaikan sehari-hari, dengan stok yang bisa diandalkan.'],
            ['Toko sparepart', 'Pasokan rutin untuk dijual kembali.'],
            ['Distributor', 'Volume, dengan harga dan syarat pembayaran khusus.'],
        ],
        'cara' => 'Cara kerjanya',
        'langkah' => [
            'Pendaftaran akun pelanggan dan verifikasi data usaha.',
            'Harga dan limit kredit disepakati bersama.',
            'Pesanan lewat tim sales kami atau portal pelanggan.',
            'Pengiriman dengan surat jalan dan faktur pajak.',
        ],
        'rincian' => 'Data perusahaan',
        'nama' => 'Nama',
        'bentuk' => 'Bentuk badan',
        'nib' => 'NIB',
        'berdiri' => 'Berdiri',
        'kota' => 'Kota',
        'hubungi' => 'Hubungi kami',
    ],

    'mitra' => [
        'judul' => 'Mitra',
        'deskripsi' => 'Perusahaan yang bekerja sama dengan :nama.',
        'lede' => 'Perusahaan yang bekerja sama dengan kami dalam pasokan, distribusi, dan logistik.',
        'sejak' => 'sejak',
        'kosong' => 'Belum ada mitra yang dicantumkan.',
        'ajak_judul' => 'Tertarik bekerja sama?',
        'ajak_lede' => 'Kami terbuka untuk kemitraan dalam pasokan, distribusi wilayah, dan logistik.',
        'hubungi' => 'Hubungi kami',
    ],

    'rencana' => [
        'judul' => 'Rencana Pengembangan',
        'deskripsi' => 'Apa yang sedang dibangun :nama selanjutnya.',
        'lede' => 'Yang sedang kami kerjakan sekarang, dan yang akan datang.',
        'selesai' => 'Selesai',
        'berjalan' => 'Berjalan',
        'rencana' => 'Direncanakan',
        'catatan' => 'Rencana dapat berubah mengikuti kebutuhan pelanggan dan kesiapan operasional.',
    ],

    'kontak' => [
        'judul' => 'Kontak',
        'deskripsi' => 'Hubungi :nama untuk pemesanan, akun pelanggan, atau kemitraan.',
        'lede' => 'Untuk pemesanan, pembukaan akun pelanggan, atau kemitraan.',
        'rincian' => 'Rincian kontak',
        'alamat' => 'Alamat',
        'telepon' => 'Telepon',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'jam' => 'Jam operasional',
        'buka_akun' => 'Ingin membuka akun pelanggan?',
        'buka_akun_lede' => 'Akun grosir dibuka setelah data usaha Anda diverifikasi. Kirim pesan lewat WhatsApp atau email dengan nama usaha, alamat, dan NPWP.',
        'kirim_wa' => 'Kirim pesan WhatsApp',
        'sudah_akun' => 'Sudah punya akun?',
        'sudah_akun_lede' => 'Masuk untuk melihat harga Anda, sisa limit kredit, dan faktur.',
        'masuk_akun' => 'Masuk ke akun Anda',
        'cabang' => 'Cabang kami',
        'cabang_lede' => 'Kami melayani dari beberapa cabang. Izinkan lokasi untuk melihat cabang terdekat dari Anda.',
        'cabang_terdekat' => 'Cari cabang terdekat',
        'cabang_mencari' => 'Mencari lokasi Anda…',
        'cabang_hasil' => 'Cabang terdekat: :nama, sekitar :jarak km.',
        'cabang_ditolak' => 'Lokasi tidak diizinkan. Daftar cabang tetap bisa dilihat di bawah.',
        'cabang_tidak_ada' => 'Belum ada cabang yang punya koordinat, jadi jarak belum bisa dihitung.',
        'cabang_tanpa_dukungan' => 'Peramban Anda tidak mendukung lokasi.',
    ],

    'masuk' => [
        'judul' => 'Masuk',
        'deskripsi' => 'Masuk ke portal pelanggan atau panel staf :nama.',
        'pilih' => 'Pilih jenis akun Anda.',
        'pelanggan' => 'Pelanggan',
        'portal' => 'Portal Pelanggan',
        'portal_lede' => 'Untuk bengkel, toko sparepart, dan distributor terdaftar. Lihat sisa limit kredit, faktur, dan riwayat pesanan.',
        'masuk_pelanggan' => 'Masuk sebagai pelanggan',
        'staf' => 'Staf',
        'admin' => 'Panel Admin',
        'admin_lede' => 'Untuk tim sales, gudang, keuangan, dan pemilik. Kelola pesanan, stok, penagihan, dan daftar harga.',
        'masuk_staf' => 'Masuk sebagai staf',
        'belum_akun' => 'Belum punya akun pelanggan?',
        'hubungi' => 'Hubungi kami',
        'untuk_daftar' => 'untuk mendaftar.',
    ],

    'persetujuan' => [
        'judul' => 'Cookie dan lokasi',
        'isi' => 'Situs ini hanya memakai cookie teknis: sesi, keamanan formulir, dan pilihan bahasa Anda. Tidak ada pelacakan, tidak ada iklan.',
        'lokasi' => 'Bila Anda mengizinkan, lokasi dipakai sekali untuk menunjukkan cabang terdekat — tidak disimpan dan tidak dikirim ke pihak lain.',
        'baca' => 'Baca kebijakan privasi',
        'mengerti' => 'Mengerti',
        'izinkan_lokasi' => 'Izinkan lokasi',
    ],
];
