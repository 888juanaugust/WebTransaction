<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kebijakan Privasi dan Syarat Penjualan
|--------------------------------------------------------------------------
|
| The knobs in the two legal pages: effective dates, the channel for privacy
| requests, and the commercial terms that are the owner's decision rather than
| the developer's.
|
| >>> BOTH PAGES ARE A DRAFT AND MUST BE REVIEWED BY A LAWYER BEFORE LAUNCH.
| >>> They are written to be accurate about what the system actually does —
| >>> which is the part a lawyer cannot check for you — but accuracy about the
| >>> software is not the same thing as legal sufficiency.
|
| Two of the values below are genuine business decisions, marked >>> PUTUSKAN.
| The rest are anchored in law and carry the citation next to them.
|
| The prose lives in the Blade templates, not here. A privacy notice is one
| continuous argument; chopping it into config strings makes it unreadable and
| makes it easy to leave a paragraph contradicting the one above it. What lives
| here is what changes: dates, a rate, an address.
|
| The list of personal data is neither here nor in the template — it is in
| App\Support\Legal\DataInventory, which is checked against the database schema
| by a test, so the notice cannot quietly stop being true.
|
*/

return [

    /*
     | Kebijakan Privasi
     |--------------------------------------------------------------------
     |
     | UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi ("UU PDP").
     */
    'privasi' => [

        /*
         | The date this version took effect. Bump it whenever the notice
         | changes in substance — a reader has to be able to tell whether the
         | policy they agreed to is the one on screen.
         */
        'berlaku_sejak' => env('LEGAL_PRIVASI_BERLAKU', '2026-08-12'),
        'versi' => env('LEGAL_PRIVASI_VERSI', '1.0'),

        /*
         | Where a data subject sends a request to access, correct or delete
         | their data. UU PDP gives them those rights and expects a working
         | channel, so this must be an address a human actually reads.
         |
         | Defaults to the general company email so the page never prints a
         | dead address; set a dedicated one once there is somebody to own it.
         */
        'email' => env('LEGAL_PRIVASI_EMAIL', env('PERUSAHAAN_EMAIL', 'sales@example.com')),

        /*
         | UU PDP Pasal 37: a correction request must be answered within
         | 3 × 24 hours. Pasal 46: a breach must be notified to the affected
         | subjects and to the supervisory authority within 3 × 24 hours.
         | Stated here so the page and the operational runbook cannot disagree.
         */
        'batas_waktu_koreksi_jam' => 72,
        'batas_waktu_pemberitahuan_insiden_jam' => 72,

        /*
         | A Pejabat Pelindungan Data Pribadi (DPO) is mandatory only in the
         | cases in UU PDP Pasal 53 — public service, large-scale systematic
         | monitoring, or large-scale processing of specific personal data.
         | A wholesale parts distributor is unlikely to meet any of them, so
         | this is null and requests go to the address above.
         |
         | >>> Confirm with counsel rather than assuming.
         */
        'dpo' => env('LEGAL_PRIVASI_DPO'),

        /*
         | Where the data physically sits. Single VPS in Jakarta, so there is
         | no cross-border transfer to disclose under UU PDP Pasal 56 — which
         | is a genuinely good position and worth stating plainly.
         */
        'lokasi_server' => env('LEGAL_LOKASI_SERVER', 'Jakarta, Indonesia'),
    ],

    /*
     | Syarat Penjualan
     |--------------------------------------------------------------------
     |
     | B2B terms. Buyers are registered businesses buying for resale or for
     | their own workshop use, not end consumers.
     */
    'syarat' => [

        'berlaku_sejak' => env('LEGAL_SYARAT_BERLAKU', '2026-08-12'),
        'versi' => env('LEGAL_SYARAT_VERSI', '1.0'),

        /*
         | >>> PUTUSKAN: denda keterlambatan.
         |
         | 2% per month of the overdue amount is ordinary Indonesian commercial
         | practice, but it is an agreed rate, not a statutory one — the parties
         | set it between them. Confirm the number the business actually wants
         | to enforce, because a rate nobody intends to charge is worse than no
         | rate at all: it makes the whole document look decorative.
         */
        'denda_persen_per_bulan' => env('LEGAL_DENDA_PERSEN', 2),

        /*
         | >>> PUTUSKAN: batas waktu klaim barang.
         |
         | How many days after delivery a buyer may raise a shortage, a wrong
         | item, or visible damage. Short windows are normal in parts wholesale
         | because stock moves on, but the number has to be one the warehouse
         | can actually live with.
         */
        'batas_klaim_hari' => env('LEGAL_BATAS_KLAIM_HARI', 3),

        /*
         | How long a confirmed order holds its stock reservation before a
         | scheduled job releases it. This is not a policy choice — it is read
         | from the setting ReleaseStaleReservations actually obeys, so the
         | terms cannot promise a window the software does not honour.
         */
        'kadaluarsa_reservasi_jam' => intdiv((int) env('RESERVATION_TTL_MINUTES', 2880), 60),

        /*
         | Forum penyelesaian sengketa. Defaults to the courts of the city the
         | company operates from.
         */
        'forum_sengketa' => env('LEGAL_FORUM', 'Pengadilan Negeri Jakarta Pusat'),
    ],

];
