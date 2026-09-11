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
| The copy in this file is what the public site prints, and the public site
| is bilingual (2026-09): Bahasa Indonesia by default, English on request —
| a switch in the header, remembered in a cookie. Everything the company
| says about itself is therefore a pair, `['id' => …, 'en' => …]`, and
| App\Support\Perusahaan picks the side the visitor asked for. The panels
| and every printed document stay in Bahasa Indonesia and never read these.
|
| A pair must carry both sides. The file once held pairs that drifted apart
| and was collapsed to English for a year; it is bilingual again because the
| owner asked for the option, and this time a test walks the file and fails
| the build on a pair with a side missing — drift is caught, not tolerated.
| The one non-pair by design is `kontak.jam_operasional` beside
| `kontak.business_hours`: the same hours, but one is printed on Indonesian
| documents whatever the site speaks, so they are two facts, not one pair.
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
     | The mark is in the repository (public/images/logo.svg, traced from the
     | company's logo file), so it is the default. Every surface still falls
     | back to the wordmark when the path is blank or the file is missing, so
     | a bad value is a plain text logo rather than a broken image on the
     | shopfront. Its dark-mode twin, logo-dark.svg, is found by name.
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
    'logo' => env('PERUSAHAAN_LOGO', 'images/logo.svg'),

    'tagline' => [
        'id' => 'Distributor grosir suku cadang otomotif',
        'en' => 'Wholesale distributor of automotive spare parts',
    ],

    /*
     | One paragraph for the hero. Written for workshops, parts shops and
     | distributors, not retail buyers.
     */
    'ringkasan' => [
        'id' => 'Kami memasok suku cadang otomotif secara grosir ke bengkel, toko sparepart, '
            .'dan distributor di seluruh Indonesia. Stok siap kirim, harga ditetapkan per '
            .'pelanggan, dan penagihan yang tertib.',
        'en' => 'We supply automotive spare parts wholesale to workshops, parts shops '
            .'and distributors across Indonesia. Stock ready to ship, prices set per customer, '
            .'and invoicing kept in order.',
    ],

    'profil' => [
        'id' => [
            'Kami adalah distributor grosir suku cadang otomotif. Kami melayani bengkel, '
                .'toko sparepart, dan distributor — bukan pembeli eceran.',

            'Lewat jaringan pemasok yang dibangun bertahun-tahun, kami menyediakan stok '
                .'kategori yang paling sering dibutuhkan bengkel: hydraulic part, suspension '
                .'part, electric part, dan bearing.',

            'Setiap pelanggan terdaftar mendapat harga yang disepakati, limit kredit yang '
                .'jelas, dan faktur pajak yang sesuai ketentuan.',
        ],
        'en' => [
            'We are a wholesale distributor of automotive spare parts. We serve workshops, '
                .'parts shops and distributors, not retail buyers.',

            'Through a supplier network built over many years, we keep stock of the categories '
                .'workshops need most often: hydraulic parts, suspension parts, electric parts '
                .'and bearings.',

            'Every registered customer gets prices set by agreement, a clear credit limit, '
                .'and tax invoices that meet the applicable regulations.',
        ],
    ],

    // Legal identity. Required on the site once PSE registration is done.
    'legal' => [
        'bentuk_badan' => env('PERUSAHAAN_BENTUK', 'PT'),
        'nib' => env('PERUSAHAAN_NIB'),
        'npwp' => env('PERUSAHAAN_NPWP'),
        'tahun_berdiri' => env('PERUSAHAAN_TAHUN', '2020'),
    ],

    /*
     | Rekening perusahaan — where customers send their transfers.
     |
     | There is no payment gateway: the business sells on credit and is paid
     | by transfer, cash or giro, confirmed by finance against the bank
     | statement. This block is what the faktur and the portal print as
     | payment instructions.
     |
     | >>> PLACEHOLDER — replace with the real account before the first
     | >>> faktur goes out. A wrong number here sends customer money to
     | >>> somebody else's account.
     */
    'rekening' => [
        'bank' => env('PERUSAHAAN_BANK', 'BCA'),
        'nomor' => env('PERUSAHAAN_REKENING', '000-000-0000'),
        'atas_nama' => env('PERUSAHAAN_REKENING_NAMA', 'PT Java Indo Intermechanika'),
    ],

    'kontak' => [
        'alamat' => env('PERUSAHAAN_ALAMAT', 'Jl. Contoh No. 1, Jakarta, Indonesia'),
        'kota' => env('PERUSAHAAN_KOTA', 'Jakarta'),
        'telepon' => env('PERUSAHAAN_TELEPON', '+62 21 0000 0000'),
        'whatsapp' => env('PERUSAHAAN_WHATSAPP', '+62 800 0000 0000'),
        'email' => env('PERUSAHAAN_EMAIL', 'sales@example.com'),
        // Printed on Indonesian documents (the purchase order, the terms of sale).
        'jam_operasional' => 'Senin–Jumat 08.00–17.00, Sabtu 08.00–13.00 WIB',
        // The same hours for the English site. Keep the two in step.
        'business_hours' => 'Monday to Friday 08.00-17.00, Saturday 08.00-13.00 WIB',
    ],

    /*
     | Merk yang kami bawa. Brand names are proper nouns — one spelling only.
     */
    'merk' => ['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX'],

    /*
     | Category names are the industry's own terms and are used verbatim in
     | the price list and the catalogue.
     */
    'kategori' => [
        [
            'nama' => 'HYDRAULIC PART',
            'deskripsi' => [
                'id' => 'Komponen sistem hidraulik untuk kendaraan penumpang dan niaga.',
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
                'id' => 'Bearing dan komponen berputar.',
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
            'nama' => 'Partner Name One',
            'negara' => 'Indonesia',
            'sejak' => '2021',
            'bidang' => 'Parts supplier',
            'deskripsi' => 'A short description of the partnership with this company.',
        ],
        [
            'nama' => 'Partner Name Two',
            'negara' => 'Indonesia',
            'sejak' => '2022',
            'bidang' => 'Regional distribution',
            'deskripsi' => 'A short description of the partnership with this company.',
        ],
        [
            'nama' => 'Partner Name Three',
            'negara' => 'Indonesia',
            'sejak' => '2023',
            'bidang' => 'Logistics',
            'deskripsi' => 'A short description of the partnership with this company.',
        ],
    ],

    /*
     | Promo — the carousel at the top of the landing page.
     |
     | Empty here on purpose: promotions are typed by the Owner in Pengaturan
     | perusahaan and overlaid at boot, exactly like the partners. Each entry
     | is {judul, teks, gambar, tautan, aktif}; `gambar` is a path on the
     | `public` disk. With no active entry the landing page simply has no
     | carousel — there is no placeholder slide to forget to remove.
     */
    'promo' => [],

    /*
     | Rencana pengembangan — the "future works" page.
     |
     | This is public, so it has to stay honest: an item marked `rencana` that
     | actually shipped reads as a company that does not know what it built.
     | `status` is one of: selesai | berjalan | rencana.
     */
    'rencana' => [
        [
            'judul' => ['id' => 'Sistem operasional internal', 'en' => 'Internal operations system'],
            'status' => 'berjalan',
            'deskripsi' => [
                'id' => 'Pesanan, stok, dan penagihan dicatat langsung oleh tim kami sendiri.',
                'en' => 'Orders, stock and invoicing recorded directly by our own team.',
            ],
        ],
        [
            'judul' => ['id' => 'Penjualan kredit dengan penagihan tertib', 'en' => 'Credit sales with orderly collection'],
            'status' => 'berjalan',
            'deskripsi' => [
                'id' => 'Pembayaran lewat transfer bank, tunai, atau giro, dikonfirmasi dan '
                    .'direkonsiliasi oleh tim keuangan kami.',
                'en' => 'Payment by bank transfer, cash or giro, confirmed and reconciled '
                    .'by our finance team.',
            ],
        ],
        [
            'judul' => ['id' => 'Portal pelanggan', 'en' => 'Customer portal'],
            'status' => 'berjalan',
            'deskripsi' => [
                'id' => 'Pelanggan terdaftar dapat memantau kredit, faktur, dan riwayat pesanannya.',
                'en' => 'Registered customers can follow their credit, invoices and order history.',
            ],
        ],
        [
            'judul' => ['id' => 'Pemesanan mandiri lewat portal', 'en' => 'Self-service ordering through the portal'],
            'status' => 'berjalan',
            'deskripsi' => [
                'id' => 'Pelanggan dapat mengulang pesanan sebelumnya, menyusun keranjang, dan '
                    .'mengirim pesanan sendiri.',
                'en' => 'Customers can repeat a previous order, build a cart and submit '
                    .'orders themselves.',
            ],
        ],
        [
            'judul' => ['id' => 'Katalog produk daring', 'en' => 'Online product catalogue'],
            'status' => 'berjalan',
            'deskripsi' => [
                'id' => 'Katalog lengkap dengan harga masing-masing pelanggan, bisa dicari '
                    .'menurut merk, kategori, dan jenis kendaraan.',
                'en' => 'The full catalogue at each customer\'s own prices, searchable '
                    .'by brand, category and vehicle type.',
            ],
        ],
        [
            'judul' => ['id' => 'Faktur pajak elektronik (Coretax)', 'en' => 'Electronic tax invoices (Coretax)'],
            'status' => 'rencana',
            'deskripsi' => [
                'id' => 'Ekspor faktur pajak dalam format impor Coretax.',
                'en' => 'Tax invoice export in the Coretax import format.',
            ],
        ],
    ],

];
