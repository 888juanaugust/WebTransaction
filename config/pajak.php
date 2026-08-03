<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PPN (Pajak Pertambahan Nilai)
|--------------------------------------------------------------------------
|
| Headline rate is 12%. For ordinary (non-luxury) goods the effective burden
| stays at 11%, because the DPP is 11/12 x harga jual — PMK 131/2024. In
| Coretax that is transaction code 04 ("DPP Nilai Lain"), not 01.
|
| Do not change these numbers without confirming with the accountant.
|
*/

return [

    // Rate applied to the DPP, in basis points. 1200 bps = 12%.
    'ppn_rate_bps' => (int) env('PPN_RATE_BPS', 1200),

    // DPP Nilai Lain factor: dpp = harga_jual * numerator / denominator.
    'dpp_factor' => [
        'numerator' => (int) env('PPN_DPP_NUMERATOR', 11),
        'denominator' => (int) env('PPN_DPP_DENOMINATOR', 12),
    ],

    // Coretax transaction code written into the faktur CSV export.
    'kode_transaksi' => env('PPN_TRANSACTION_CODE', '04'),

    // Identity of the seller (PT/CV) as it must appear on the faktur.
    'penjual' => [
        'npwp' => env('PAJAK_PENJUAL_NPWP'),
        'nama' => env('PAJAK_PENJUAL_NAMA'),
        'alamat' => env('PAJAK_PENJUAL_ALAMAT'),
    ],

];
