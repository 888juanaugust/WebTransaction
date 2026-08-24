<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Profil perusahaan / Company profile
|--------------------------------------------------------------------------
|
| Everything the public site shows about the company lives here — one file,
| so marketing copy never gets scattered across Blade templates.
|
| >>> THE TEXT BELOW IS PLACEHOLDER. Replace it with the real company
| >>> profile, the real joint-venture partners, and the real contact
| >>> details before this site is published.
|
| LANGUAGE
| --------
| Bahasa Indonesia throughout — the public site, both panels, all of it.
|
| This file used to hold ['id' => ..., 'en' => ...] pairs, because the home
| page alone was in English. That meant one sentence had two versions to keep
| in step, and they had already begun to drift. One language, one copy.
|
| Note: no prices anywhere on the public site. Public price display is
| explicitly out of scope for v1.
|
*/

return [

    'nama' => env('PERUSAHAAN_NAMA', 'PT Java Indo Intermechanika'),
    'nama_singkat' => env('PERUSAHAAN_NAMA_SINGKAT', 'Java Indo'),

    /*
     | Path to the company mark, relative to public/ — e.g. 'images/logo.svg'.
     |
     | Null until the file is actually in the repository. Every surface falls
     | back to the wordmark when this is unset, so a missing file is a plain
     | text logo rather than a broken image on the shopfront.
     |
     | SVG for preference: the mark is flat colour and it has to stay crisp on
     | a phone, in the panel sidebar, and on a printed surat jalan.
     |
     | >>> Use the mark on its own, without the company name under it.
     |
     | When this is set, Filament renders the image *instead of* the wordmark —
     | there is no text beside it to be redundant with. The panel draws it at
     | 1.75rem tall (28px), so a full lockup with "PT JAVA INDO INTERMECHANIKA"
     | beneath the mark reduces that name to about four pixels of grey mush.
     | Keep the lockup for letterheads; the panel wants the mark alone.
     */
    'logo' => env('PERUSAHAAN_LOGO'),

    'tagline' => 'Distributor grosir suku cadang otomotif',

    /*
     | One paragraph for the hero. Written for bengkel, toko sparepart and
     | distributors — not retail buyers.
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
     | Merk yang kami bawa. Brand names are proper nouns — one spelling only.
     */
    'merk' => ['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX'],

    /*
     | Category names are the industry's own English terms and are used
     | verbatim in the price list and the catalogue, so they are not
     | translated — only their descriptions are.
     */
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
     | Perusahaan mitra / joint-venture partners.
     |
     | >>> PLACEHOLDER — replace with the real partners. Naming a company as a
     | >>> partner in public is a claim about a real business relationship, so
     | >>> only list organisations that have actually agreed to appear here.
     */
    'mitra' => [
        [
            'nama' => 'Nama Mitra Satu',
            'negara' => 'Indonesia',
            'sejak' => '2021',
            'bidang' => 'Pemasok suku cadang',
            'deskripsi' => 'Deskripsi singkat kerja sama dengan mitra ini.',
        ],
        [
            'nama' => 'Nama Mitra Dua',
            'negara' => 'Indonesia',
            'sejak' => '2022',
            'bidang' => 'Distribusi regional',
            'deskripsi' => 'Deskripsi singkat kerja sama dengan mitra ini.',
        ],
        [
            'nama' => 'Nama Mitra Tiga',
            'negara' => 'Indonesia',
            'sejak' => '2023',
            'bidang' => 'Logistik',
            'deskripsi' => 'Deskripsi singkat kerja sama dengan mitra ini.',
        ],
    ],

    /*
     | Rencana pengembangan — the "future works" page.
     |
     | This is public, so it has to stay honest: an item marked `rencana` that
     | actually shipped reads as a company that does not know what it built.
     | `status` is one of: selesai | berjalan | rencana.
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
            'status' => 'berjalan',
            'deskripsi' => 'Pelanggan dapat mengulang order sebelumnya, menyusun keranjang, '
                .'dan mengajukan pesanan sendiri.',
        ],
        [
            'judul' => 'Katalog produk daring',
            'status' => 'berjalan',
            'deskripsi' => 'Katalog lengkap dengan harga khusus per pelanggan, '
                .'dapat dicari berdasarkan merk, kategori, dan tipe mobil.',
        ],
        [
            'judul' => 'Faktur pajak elektronik (Coretax)',
            'status' => 'rencana',
            'deskripsi' => 'Ekspor faktur pajak sesuai format impor Coretax.',
        ],
    ],

];
