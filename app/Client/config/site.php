<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public site's copy
|--------------------------------------------------------------------------
|
| Everything the public site says about the company lives here, so marketing
| copy never scatters across templates. The site is bilingual: Bahasa
| Indonesia by default, English on request. Everything the company says about
| itself is therefore a pair, ['id' => …, 'en' => …], and App\Client\Site\Copy
| picks the side the visitor asked for. A pair must carry both sides;
| SiteCopyTest walks this file and fails the build on a side missing.
|
| The Owner overrides any key here from the Website screen (site_settings);
| what is typed there wins over what is written here.
|
| No prices anywhere on the public site.
|
*/

return [

    // The short name in the header; the legal name comes from Preferences (the letterhead).
    'short_name' => env('SITE_SHORT_NAME', config('app.name', 'Central')),

    'tagline' => [
        'id' => 'Distributor grosir suku cadang otomotif',
        'en' => 'Wholesale distributor of automotive spare parts',
    ],

    // One paragraph for the hero: written for workshops, parts shops and distributors, never retail buyers.
    'summary' => [
        'id' => 'Kami memasok suku cadang otomotif secara grosir ke bengkel, toko sparepart, dan distributor di seluruh Indonesia. Stok siap kirim dari cabang terdekat, harga ditetapkan per pelanggan, dan penagihan yang tertib.',
        'en' => 'We supply automotive spare parts wholesale to workshops, parts shops and distributors across Indonesia. Stock ships from the nearest branch, prices are set per customer, and invoicing is kept in order.',
    ],

    'profile' => [
        'id' => [
            'Kami adalah distributor grosir suku cadang otomotif. Kami melayani bengkel, toko sparepart, dan distributor, bukan pembeli eceran.',
            'Lewat jaringan pemasok yang dibangun bertahun-tahun, kami menyediakan stok kategori yang paling sering dibutuhkan bengkel: hydraulic part, suspension part, electric part, dan bearing part.',
            'Setiap pelanggan terdaftar mendapat harga yang disepakati, limit kredit yang jelas, dan faktur pajak yang sesuai ketentuan.',
        ],
        'en' => [
            'We are a wholesale distributor of automotive spare parts. We serve workshops, parts shops and distributors, not retail buyers.',
            'Through a supplier network built over many years, we keep stock of the categories workshops need most often: hydraulic parts, suspension parts, electric parts and bearings.',
            'Every registered customer gets prices set by agreement, a clear credit limit, and tax invoices that meet the regulations.',
        ],
    ],

    // Who buys from us.
    'serves' => [
        [
            'title' => ['id' => 'Bengkel', 'en' => 'Workshops'],
            'text' => ['id' => 'Suku cadang yang paling sering diganti, siap kirim dalam jumlah yang masuk akal untuk bengkel.', 'en' => 'The parts replaced most often, ready to ship in quantities that make sense for a workshop.'],
        ],
        [
            'title' => ['id' => 'Toko sparepart', 'en' => 'Parts shops'],
            'text' => ['id' => 'Stok tetap untuk rak toko, dalam dus dan karton, dengan harga grosir yang disepakati.', 'en' => 'Steady stock for the shelf, by the box and the carton, at agreed wholesale prices.'],
        ],
        [
            'title' => ['id' => 'Distributor', 'en' => 'Distributors'],
            'text' => ['id' => 'Pasokan berkala dalam volume besar, dengan termin kredit dan dokumen yang rapi.', 'en' => 'Regular supply in volume, on credit terms, with the paperwork in order.'],
        ],
    ],

    // How an account opens: the three steps between interested and ordering.
    'steps' => [
        [
            'title' => ['id' => 'Hubungi kami', 'en' => 'Get in touch'],
            'text' => ['id' => 'Kirim nama usaha, kota, dan jenis suku cadang yang Anda butuhkan. Tim penjualan cabang terdekat akan menghubungi Anda.', 'en' => 'Send your business name, city and the kind of parts you need. The sales team of the nearest branch gets back to you.'],
        ],
        [
            'title' => ['id' => 'Akun dan harga disepakati', 'en' => 'Account and prices agreed'],
            'text' => ['id' => 'Kami memverifikasi legalitas usaha, lalu menyepakati harga, limit kredit, dan termin pembayaran Anda.', 'en' => 'We verify the business, then agree your prices, credit limit and payment terms.'],
        ],
        [
            'title' => ['id' => 'Pesan lewat portal', 'en' => 'Order through the portal'],
            'text' => ['id' => 'Ulangi pesanan terakhir, lihat sisa kredit, dan unduh faktur serta surat jalan kapan saja.', 'en' => 'Repeat your last order, see your free credit, and download invoices and delivery notes any time.'],
        ],
    ],

    // Brand names are proper nouns: one spelling only.
    'brands' => ['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX'],

    // Category names are the industry's own terms, used verbatim in the price list.
    'categories' => [
        ['name' => 'HYDRAULIC PART', 'description' => ['id' => 'Komponen sistem hidraulik untuk kendaraan penumpang dan niaga.', 'en' => 'Hydraulic system components for passenger and commercial vehicles.']],
        ['name' => 'SUSPENSION PART', 'description' => ['id' => 'Komponen kaki-kaki dan sistem suspensi.', 'en' => 'Undercarriage and suspension system components.']],
        ['name' => 'ELECTRIC PART', 'description' => ['id' => 'Komponen kelistrikan kendaraan.', 'en' => 'Vehicle electrical components.']],
        ['name' => 'BEARING PART', 'description' => ['id' => 'Bearing dan komponen berputar.', 'en' => 'Bearings and rotating components.']],
    ],

    // Partners. Naming a company here is a public claim about a real relationship: list only those who agreed.
    'partners' => [
        ['name' => 'Partner Name One', 'country' => 'Indonesia', 'since' => '2021', 'field' => ['id' => 'Pemasok suku cadang', 'en' => 'Parts supplier'], 'description' => ['id' => 'Uraian singkat kerja sama dengan perusahaan ini.', 'en' => 'A short description of the partnership with this company.']],
        ['name' => 'Partner Name Two', 'country' => 'Indonesia', 'since' => '2022', 'field' => ['id' => 'Distribusi wilayah', 'en' => 'Regional distribution'], 'description' => ['id' => 'Uraian singkat kerja sama dengan perusahaan ini.', 'en' => 'A short description of the partnership with this company.']],
        ['name' => 'Partner Name Three', 'country' => 'Indonesia', 'since' => '2023', 'field' => ['id' => 'Logistik', 'en' => 'Logistics'], 'description' => ['id' => 'Uraian singkat kerja sama dengan perusahaan ini.', 'en' => 'A short description of the partnership with this company.']],
    ],

    // The roadmap page. Public, so it stays honest: status is done | ongoing | planned.
    'roadmap' => [
        ['title' => ['id' => 'Sistem operasional internal', 'en' => 'Internal operations system'], 'status' => 'done', 'description' => ['id' => 'Pesanan, stok, dan penagihan dicatat langsung oleh tim kami sendiri, per cabang, dalam satu pembukuan.', 'en' => 'Orders, stock and invoicing recorded directly by our own team, per branch, in one set of books.']],
        ['title' => ['id' => 'Penjualan kredit dengan penagihan tertib', 'en' => 'Credit sales with orderly collection'], 'status' => 'done', 'description' => ['id' => 'Pembayaran lewat transfer bank, tunai, atau giro, dikonfirmasi dan direkonsiliasi oleh tim keuangan kami.', 'en' => 'Payment by bank transfer, cash or giro, confirmed and reconciled by our finance team.']],
        ['title' => ['id' => 'Portal pelanggan', 'en' => 'Customer portal'], 'status' => 'done', 'description' => ['id' => 'Pelanggan terdaftar mengulang pesanan, memantau kredit dan faktur, dan mengunduh surat jalan.', 'en' => 'Registered customers repeat orders, follow their credit and invoices, and download delivery notes.']],
        ['title' => ['id' => 'Katalog dengan harga masing-masing pelanggan', 'en' => 'Catalogue at each customer\'s own prices'], 'status' => 'ongoing', 'description' => ['id' => 'Katalog lengkap di portal, bisa dicari menurut merk, kategori, dan jenis kendaraan.', 'en' => 'The full catalogue in the portal, searchable by brand, category and vehicle.']],
        ['title' => ['id' => 'Faktur pajak elektronik (Coretax)', 'en' => 'Electronic tax invoices (Coretax)'], 'status' => 'planned', 'description' => ['id' => 'Ekspor faktur pajak dalam format impor Coretax.', 'en' => 'Tax invoice export in the Coretax import format.']],
    ],

    'contact' => [
        'phone' => env('SITE_PHONE', '+62 21 0000 0000'),
        'whatsapp' => env('SITE_WHATSAPP', '+62 800 0000 0000'),
        'email' => env('SITE_EMAIL', 'sales@example.com'),
        'city' => env('SITE_CITY', 'Jakarta'),
        'hours' => [
            'id' => 'Senin sampai Jumat 08.00 sampai 17.00, Sabtu 08.00 sampai 13.00 WIB',
            'en' => 'Monday to Friday 08.00 to 17.00, Saturday 08.00 to 13.00 WIB',
        ],
    ],

    // Legal identity and the values the two legal pages cite.
    'legal' => [
        'entity' => env('SITE_ENTITY', 'PT'),
        'nib' => env('SITE_NIB'),
        'established' => env('SITE_ESTABLISHED'),
        'privacy' => [
            'effective_since' => '2026-10-01',
            'version' => '1.0',
            'email' => env('SITE_PRIVACY_EMAIL'),
            'correction_hours' => 72,
            'breach_notice_hours' => 72,
            'server_location' => 'Jakarta, Indonesia',
        ],
        'terms' => [
            'effective_since' => '2026-10-01',
            'version' => '1.0',
            'late_fee_percent_per_month' => 2,
            'claim_days' => 3,
            'dispute_forum' => 'Pengadilan Negeri Jakarta Pusat',
        ],
    ],

];
