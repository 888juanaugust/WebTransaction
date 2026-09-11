<?php

declare(strict_types=1);

/*
 * The public site's own words, in English — the option.
 *
 * Keyed identically to lang/id/publik.php; a key present in one and not the
 * other is a page that switches language mid-sentence, and the test that
 * compares the two key sets is what stops that.
 */
return [
    'nama_bahasa' => 'English',
    'ganti_bahasa' => 'Bahasa Indonesia',
    'ganti_bahasa_label' => 'Ganti ke Bahasa Indonesia',

    'menu' => [
        'beranda' => 'Home',
        'tentang' => 'About Us',
        'mitra' => 'Partners',
        'rencana' => 'Roadmap',
        'kontak' => 'Contact',
        'masuk' => 'Sign in',
        'lewati' => 'Skip to content',
        'buka' => 'Open menu',
        'tutup' => 'Close menu',
        'utama' => 'Main',
    ],

    'kaki' => [
        'halaman' => 'Pages',
        'hukum' => 'Legal',
        'privasi' => 'Privacy Policy',
        'syarat' => 'Terms of Sale',
        'kontak' => 'Contact',
        'hak_cipta' => 'All rights reserved.',
        'harga_grosir' => 'Wholesale prices are for registered customers only.',
    ],

    'beranda' => [
        'judul' => 'Home',
        'hero' => 'Wholesale automotive parts, priced per customer.',
        'masuk_akun' => 'Sign in to your account',
        'hubungi' => 'Contact us',
        'melayani' => 'We supply :siapa, not retail buyers. Wholesale prices are for registered customers only.',
        'melayani_siapa' => 'workshops, parts shops and distributors',
        'fakta' => [
            'kategori' => 'Product categories',
            'merk' => 'Brands supplied',
            'gudang' => 'Main warehouse',
            'tempo' => 'Standard credit term',
            'tempo_nilai' => '30 days',
        ],
        'kategori_judul' => 'Product categories',
        'kategori_lede' => 'The four categories workshops need most often, kept in stock.',
        'daftar_lengkap' => 'The full product list, with prices, is available to registered customers after signing in.',
        'merk_judul' => 'Brands we carry, available for delivery across Indonesia',
        'langkah_judul' => 'Become a customer in three steps',
        'langkah_lede' => 'A wholesale account opens once your business details are verified. There is no self-service sign-up.',
        'langkah' => [
            ['Send your business details', 'Business name, address and tax number (NPWP) by WhatsApp or email. The account opens after the details are verified.'],
            ['Agree prices and a credit limit', 'Our team sets your price tier and credit limit to the scale of your business, with clear payment terms.'],
            ['Order through the portal', 'A catalogue at your prices, routine orders repeated in one click, and your invoices whenever you need them.'],
        ],
        'tentang_judul' => 'About the company',
        'tentang_lanjut' => 'More about us',
        'mitra_judul' => 'Partners',
        'mitra_semua' => 'See all partners',
        'sejak' => 'since',
        'sudah_pelanggan' => 'Already a customer?',
        'sudah_pelanggan_lede' => 'Sign in to see your prices, your remaining credit limit, invoices and order history.',
    ],

    'promo' => [
        'label' => 'Promotions',
        'sebelumnya' => 'Previous promotion',
        'berikutnya' => 'Next promotion',
        'ke' => 'Go to promotion :nomor',
        'selengkapnya' => 'Learn more',
    ],

    'tentang' => [
        'judul' => 'About Us',
        'deskripsi' => 'Profile of :nama, a wholesale distributor of automotive spare parts.',
        'profil' => 'Company profile',
        'melayani' => 'Who we serve',
        'segmen' => [
            ['Workshops', 'Day-to-day repair needs, with stock that can be relied on.'],
            ['Parts shops', 'Regular supply for resale.'],
            ['Distributors', 'Volume, with dedicated price and payment terms.'],
        ],
        'cara' => 'How it works',
        'langkah' => [
            'Customer account registration and verification of business details.',
            'Prices and a credit limit set by agreement.',
            'Orders placed through our sales team or the customer portal.',
            'Delivery with a delivery note and a tax invoice.',
        ],
        'rincian' => 'Company details',
        'nama' => 'Name',
        'bentuk' => 'Legal form',
        'nib' => 'NIB',
        'berdiri' => 'Established',
        'kota' => 'City',
        'hubungi' => 'Contact us',
    ],

    'mitra' => [
        'judul' => 'Partners',
        'deskripsi' => 'Companies that work with :nama.',
        'lede' => 'The companies we work with in supply, distribution and logistics.',
        'sejak' => 'since',
        'kosong' => 'No partners are listed yet.',
        'ajak_judul' => 'Interested in working together?',
        'ajak_lede' => 'We are open to partnerships in supply, regional distribution and logistics.',
        'hubungi' => 'Contact us',
    ],

    'rencana' => [
        'judul' => 'Roadmap',
        'deskripsi' => 'What :nama is building next.',
        'lede' => 'What we are working on now, and what comes next.',
        'selesai' => 'Completed',
        'berjalan' => 'In progress',
        'rencana' => 'Planned',
        'catatan' => 'Plans may change with customer needs and operational readiness.',
    ],

    'kontak' => [
        'judul' => 'Contact',
        'deskripsi' => 'Contact :nama for orders, a customer account, or a partnership.',
        'lede' => 'For orders, opening a customer account, or a partnership.',
        'rincian' => 'Contact details',
        'alamat' => 'Address',
        'telepon' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'jam' => 'Business hours',
        'buka_akun' => 'Want to open a customer account?',
        'buka_akun_lede' => 'Wholesale accounts open after your business details are verified. Message us on WhatsApp or by email with your business name, address and tax number (NPWP).',
        'kirim_wa' => 'Message us on WhatsApp',
        'sudah_akun' => 'Already have an account?',
        'sudah_akun_lede' => 'Sign in to see your prices, your remaining credit limit and your invoices.',
        'masuk_akun' => 'Sign in to your account',
        'cabang' => 'Our branches',
        'cabang_lede' => 'We serve from several branches. Allow location to see the one nearest to you.',
        'cabang_terdekat' => 'Find the nearest branch',
        'cabang_mencari' => 'Finding your location…',
        'cabang_hasil' => 'Nearest branch: :nama, about :jarak km away.',
        'cabang_ditolak' => 'Location was not allowed. The branch list is still shown below.',
        'cabang_tidak_ada' => 'No branch has coordinates yet, so distances cannot be worked out.',
        'cabang_tanpa_dukungan' => 'Your browser does not support location.',
    ],

    'masuk' => [
        'judul' => 'Sign in',
        'deskripsi' => 'Sign in to the customer portal or the staff panel of :nama.',
        'pilih' => 'Choose your kind of account.',
        'pelanggan' => 'Customers',
        'portal' => 'Customer Portal',
        'portal_lede' => 'For registered workshops, parts shops and distributors. See your remaining credit limit, invoices and order history.',
        'masuk_pelanggan' => 'Sign in as a customer',
        'staf' => 'Staff',
        'admin' => 'Admin Panel',
        'admin_lede' => 'For the sales, warehouse, finance and owner teams. Manage orders, stock, invoicing and price lists.',
        'masuk_staf' => 'Sign in as staff',
        'belum_akun' => 'No customer account yet?',
        'hubungi' => 'Contact us',
        'untuk_daftar' => 'to register.',
    ],

    'persetujuan' => [
        'judul' => 'Cookies and location',
        'isi' => 'This site uses technical cookies only: your session, form security, and your language choice. No tracking, no advertising.',
        'lokasi' => 'If you allow it, your location is used once to show the nearest branch — it is not stored and not sent to anyone else.',
        'baca' => 'Read the privacy policy',
        'mengerti' => 'Got it',
        'izinkan_lokasi' => 'Allow location',
    ],
];
