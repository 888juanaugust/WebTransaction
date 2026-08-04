<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Profil perusahaan
|--------------------------------------------------------------------------
|
| Everything the public site shows about the company lives here — one file,
| so marketing copy never gets scattered across Blade templates.
|
| >>> THE TEXT BELOW IS PLACEHOLDER. Replace it with the real company
| >>> profile, the real joint-venture partners, and the real contact
| >>> details before this site is published.
|
| Deliberately config rather than a database table: this content changes a
| few times a year, not daily, and a CMS for six paragraphs is a liability
| rather than a feature. If it starts changing weekly, promote it to a table
| with a Filament resource then.
|
| Note: no prices anywhere on the public site. Public price display is
| explicitly out of scope for v1.
|
*/

return [

    'nama' => env('PERUSAHAAN_NAMA', 'PT Contoh Sukses Makmur'),
    'nama_singkat' => env('PERUSAHAAN_NAMA_SINGKAT', 'WebTransaction'),
    'tagline' => 'Distributor grosir suku cadang otomotif',

    /*
     | One paragraph for the hero, a longer one for the About page. Written
     | for bengkel, toko sparepart and distributors — not retail buyers.
     */
    'ringkasan' => 'Kami memasok suku cadang otomotif secara grosir untuk bengkel, '
        .'toko sparepart, dan distributor di seluruh Indonesia. Stok siap kirim, '
        .'harga khusus per pelanggan, dan penagihan yang rapi.',

    'profil' => [
        'Perusahaan kami bergerak di bidang distribusi grosir suku cadang otomotif. '
            .'Kami melayani bengkel, toko sparepart, dan distributor — bukan pembeli eceran.',

        'Dengan jaringan pemasok yang telah terjalin bertahun-tahun, kami menjaga '
            .'ketersediaan stok untuk kategori yang paling sering dibutuhkan bengkel: '
            .'hydraulic part, suspension part, electric part, dan bearing part.',

        'Setiap pelanggan terdaftar mendapat harga sesuai kesepakatan, limit kredit '
            .'yang jelas, serta faktur pajak yang sesuai ketentuan yang berlaku.',
    ],

    // Legal identity. Required on the site once PSE registration is done.
    'legal' => [
        'bentuk_badan' => env('PERUSAHAAN_BENTUK', 'PT'),
        'nib' => env('PERUSAHAAN_NIB'),
        'npwp' => env('PERUSAHAAN_NPWP'),
        'tahun_berdiri' => env('PERUSAHAAN_TAHUN', '2020'),
    ],

    'kontak' => [
        'alamat' => env('PERUSAHAAN_ALAMAT', 'Jl. Contoh No. 1, Jakarta, Indonesia'),
        'kota' => env('PERUSAHAAN_KOTA', 'Jakarta'),
        'telepon' => env('PERUSAHAAN_TELEPON', '+62 21 0000 0000'),
        'whatsapp' => env('PERUSAHAAN_WHATSAPP', '+62 800 0000 0000'),
        'email' => env('PERUSAHAAN_EMAIL', 'sales@example.com'),
        'jam_operasional' => 'Senin–Jumat 08.00–17.00, Sabtu 08.00–13.00 WIB',
    ],

    /*
     | Merk yang kami bawa. These are the real brands from the domain spec.
     */
    'merk' => ['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX'],

    'kategori' => [
        [
            'nama' => 'HYDRAULIC PART',
            'deskripsi' => 'Komponen sistem hidrolik untuk kendaraan penumpang dan niaga.',
        ],
        [
            'nama' => 'SUSPENSION PART',
            'deskripsi' => 'Komponen kaki-kaki dan sistem suspensi.',
        ],
        [
            'nama' => 'ELECTRIC PART',
            'deskripsi' => 'Komponen kelistrikan kendaraan.',
        ],
        [
            'nama' => 'BEARING PART',
            'deskripsi' => 'Bearing dan komponen putar.',
        ],
    ],

    /*
     | Perusahaan mitra / joint venture.
     |
     | >>> PLACEHOLDER — replace with the real partners. Naming a company as a
     | >>> partner in public is a claim about a real business relationship, so
     | >>> only list organisations that have actually agreed to appear here.
     */
    'mitra' => [
        [
            'nama' => 'Nama Mitra Satu',
            'bidang' => 'Pemasok suku cadang',
            'negara' => 'Indonesia',
            'deskripsi' => 'Deskripsi singkat kerja sama dengan mitra ini.',
            'sejak' => '2021',
        ],
        [
            'nama' => 'Nama Mitra Dua',
            'bidang' => 'Distribusi regional',
            'negara' => 'Indonesia',
            'deskripsi' => 'Deskripsi singkat kerja sama dengan mitra ini.',
            'sejak' => '2022',
        ],
        [
            'nama' => 'Nama Mitra Tiga',
            'bidang' => 'Logistik',
            'negara' => 'Indonesia',
            'deskripsi' => 'Deskripsi singkat kerja sama dengan mitra ini.',
            'sejak' => '2023',
        ],
    ],

    /*
     | Rencana pengembangan — the "future works" page.
     |
     | Mirrors the real build order so the public page and the internal plan
     | cannot drift apart. `status` is one of: selesai | berjalan | rencana.
     */
    'rencana' => [
        [
            'judul' => 'Sistem operasional internal',
            'status' => 'berjalan',
            'deskripsi' => 'Pencatatan order, stok, dan penagihan dijalankan langsung oleh tim kami.',
        ],
        [
            'judul' => 'Pembayaran Virtual Account',
            'status' => 'berjalan',
            'deskripsi' => 'Pembayaran melalui Virtual Account tetap, terekonsiliasi otomatis.',
        ],
        [
            'judul' => 'Portal pelanggan',
            'status' => 'berjalan',
            'deskripsi' => 'Pelanggan terdaftar dapat memantau kredit, faktur, dan riwayat order.',
        ],
        [
            'judul' => 'Pemesanan mandiri lewat portal',
            'status' => 'rencana',
            'deskripsi' => 'Pelanggan dapat mengulang order sebelumnya dan memesan sendiri.',
        ],
        [
            'judul' => 'Katalog produk daring',
            'status' => 'rencana',
            'deskripsi' => 'Katalog lengkap dengan pencarian berdasarkan merk, kategori, dan tipe mobil.',
        ],
    ],

];
