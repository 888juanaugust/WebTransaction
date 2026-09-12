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

    // Coretax transaction code written into the faktur export.
    'kode_transaksi' => env('PPN_TRANSACTION_CODE', '04'),

    /*
    |----------------------------------------------------------------------
    | Export layout
    |----------------------------------------------------------------------
    |
    | `coretax_xml` (2026-09) is the Coretax bulk-import XML —
    | TaxInvoiceBulk / TaxInvoice / GoodService — written to the template the
    | accountant handed over. `efaktur_csv` is the older desktop e-Faktur
    | import CSV, kept so a filing made under it can still be read back and
    | reproduced; `faktur_exports.format` records which layout each past
    | filing used.
    |
    | Only the serialisation differs between the two. Which invoice, whose
    | NPWP and what the DPP is per line are settled and live in FakturRecord.
    |
    */
    'format_ekspor' => env('PAJAK_FORMAT_EKSPOR', 'coretax_xml'),

    // Identity of the seller (PT/CV) as it must appear on the faktur.
    'penjual' => [
        'npwp' => env('PAJAK_PENJUAL_NPWP'),
        'nama' => env('PAJAK_PENJUAL_NAMA'),
        'alamat' => env('PAJAK_PENJUAL_ALAMAT'),
        /*
         * ID TKU / NITKU of the place the faktur is issued from: the 16-digit
         * NPWP plus a six-digit branch suffix, `000000` for the head office.
         * Empty means head office and the writer derives it from the NPWP.
         */
        'id_tku' => env('PAJAK_PENJUAL_ID_TKU'),
    ],

    /*
    |----------------------------------------------------------------------
    | Coretax XML reference codes
    |----------------------------------------------------------------------
    |
    | Codes the XML carries that are Coretax's, not ours. Each is a config
    | value rather than a literal in the writer so the accountant can correct
    | one against the official reference lists without a code change — and
    | so the first real filing is where they get checked.
    |
    | >>> PERIKSA with the accountant before the first real filing:
    |
    |  - `negara_pembeli`: ISO 3166-1 alpha-3. Indonesia is IDN. The sample
    |    template read IND, which in that standard is India — so this is set
    |    to IDN, and the filing screen prints the value in use.
    |  - `satuan`: Coretax's unit-of-measure codes ("Referensi Satuan",
    |    UM.xxxx). The sample template carried UM.0001 for a line of goods and
    |    that is the default for both of our base units until the accountant
    |    confirms the codes for PCS and SET.
    |  - `kode_barang`: the goods code. 000000 is the general code Coretax
    |    accepts for goods with no specific classification.
    |
    */
    'coretax' => [
        'negara_pembeli' => env('PAJAK_NEGARA_PEMBELI', 'IDN'),
        'kode_barang' => env('PAJAK_KODE_BARANG', '000000'),
        'satuan' => [
            'PCS' => env('PAJAK_SATUAN_PCS', 'UM.0001'),
            'SET' => env('PAJAK_SATUAN_SET', 'UM.0001'),
        ],
    ],

];
