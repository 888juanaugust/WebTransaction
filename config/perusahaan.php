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
| The home page is English; every other public page, and both panels, are
| Bahasa Indonesia. Prose that appears in both places is therefore stored as
| ['id' => ..., 'en' => ...] and read through App\Support\Perusahaan.
|
| Fields that are the same in either language — a company name, a brand, a
| phone number, a year — stay plain strings. The accessor passes those through
| untouched, so there is no need to duplicate a proper noun.
|
| Note: no prices anywhere on the public site. Public price display is
| explicitly out of scope for v1.
|
*/

return [

    'nama' => env('PERUSAHAAN_NAMA', 'PT Contoh Sukses Makmur'),
    'nama_singkat' => env('PERUSAHAAN_NAMA_SINGKAT', 'WebTransaction'),

    'tagline' => [
        'id' => 'Distributor grosir suku cadang otomotif',
        'en' => 'Wholesale distributor of automotive spare parts',
    ],

    /*
     | One paragraph for the hero. Written for bengkel, toko sparepart and
     | distributors — not retail buyers.
     */
    'ringkasan' => [
        'id' => 'Kami memasok suku cadang otomotif secara grosir untuk bengkel, '
            .'toko sparepart, dan distributor di seluruh Indonesia. Stok siap kirim, '
            .'harga khusus per pelanggan, dan penagihan yang rapi.',
        'en' => 'We supply automotive spare parts at wholesale to workshops, parts '
            .'retailers and distributors across Indonesia. Stock ready to ship, '
            .'pricing agreed per customer, and billing you can reconcile.',
    ],

    'profil' => [
        'id' => [
            'Perusahaan kami bergerak di bidang distribusi grosir suku cadang otomotif. '
                .'Kami melayani bengkel, toko sparepart, dan distributor — bukan pembeli eceran.',

            'Dengan jaringan pemasok yang telah terjalin bertahun-tahun, kami menjaga '
                .'ketersediaan stok untuk kategori yang paling sering dibutuhkan bengkel: '
                .'hydraulic part, suspension part, electric part, dan bearing part.',

            'Setiap pelanggan terdaftar mendapat harga sesuai kesepakatan, limit kredit '
                .'yang jelas, serta faktur pajak yang sesuai ketentuan yang berlaku.',
        ],
        'en' => [
            'We are a wholesale distributor of automotive spare parts, serving workshops, '
                .'parts retailers and distributors — not retail consumers.',

            'Through supplier relationships built over many years, we hold stock in the '
                .'categories workshops need most often: hydraulic, suspension, electric '
                .'and bearing parts.',

            'Every registered customer trades on agreed pricing, a clear credit limit, '
                .'and tax invoices issued to the prevailing regulations.',
        ],
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
        'jam_operasional' => [
            'id' => 'Senin–Jumat 08.00–17.00, Sabtu 08.00–13.00 WIB',
            'en' => 'Monday–Friday 08:00–17:00, Saturday 08:00–13:00 WIB',
        ],
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
            'deskripsi' => [
                'id' => 'Komponen sistem hidrolik untuk kendaraan penumpang dan niaga.',
                'en' => 'Hydraulic system components for passenger and commercial vehicles.',
            ],
        ],
        [
            'nama' => 'SUSPENSION PART',
            'deskripsi' => [
                'id' => 'Komponen kaki-kaki dan sistem suspensi.',
                'en' => 'Undercarriage and suspension system components.',
            ],
        ],
        [
            'nama' => 'ELECTRIC PART',
            'deskripsi' => [
                'id' => 'Komponen kelistrikan kendaraan.',
                'en' => 'Vehicle electrical components.',
            ],
        ],
        [
            'nama' => 'BEARING PART',
            'deskripsi' => [
                'id' => 'Bearing dan komponen putar.',
                'en' => 'Bearings and rotating components.',
            ],
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
            'bidang' => [
                'id' => 'Pemasok suku cadang',
                'en' => 'Spare parts supplier',
            ],
            'deskripsi' => [
                'id' => 'Deskripsi singkat kerja sama dengan mitra ini.',
                'en' => 'A short description of our work with this partner.',
            ],
        ],
        [
            'nama' => 'Nama Mitra Dua',
            'negara' => 'Indonesia',
            'sejak' => '2022',
            'bidang' => [
                'id' => 'Distribusi regional',
                'en' => 'Regional distribution',
            ],
            'deskripsi' => [
                'id' => 'Deskripsi singkat kerja sama dengan mitra ini.',
                'en' => 'A short description of our work with this partner.',
            ],
        ],
        [
            'nama' => 'Nama Mitra Tiga',
            'negara' => 'Indonesia',
            'sejak' => '2023',
            'bidang' => [
                'id' => 'Logistik',
                'en' => 'Logistics',
            ],
            'deskripsi' => [
                'id' => 'Deskripsi singkat kerja sama dengan mitra ini.',
                'en' => 'A short description of our work with this partner.',
            ],
        ],
    ],

    /*
     | Rencana pengembangan — the "future works" page.
     |
     | Indonesian only: this page is not part of the English home page, so
     | there is nothing to translate it for. `status` is one of:
     | selesai | berjalan | rencana.
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
