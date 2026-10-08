<?php

/*
 * The tax office's own reference codes, printed on the e-Tax Invoice Export
 * screen and written into the Coretax bulk-import file. Confirm any change
 * with the accountant; see docs/standard/tax.md.
 */
return [
    // Which export the e-Tax screen produces by default: 'coretax' (XML bulk import) or 'legacy' (e-Faktur CSV).
    'format_ekspor' => env('PAJAK_FORMAT_EKSPOR', 'coretax'),

    'coretax' => [
        // Transaction code on the tax invoice: 04 for ordinary goods under the 11/12 tax base (PMK 131/2024), 01 otherwise.
        'trx_code_dpp_lain' => '04',
        'trx_code_normal' => '01',
        'tax_invoice_opt' => 'Normal',
        // Buyer country and document kinds.
        'buyer_country' => 'IDN',
        'buyer_document' => ['npwp' => 'TIN', 'nik' => 'National ID', 'passport' => 'Passport', 'other' => 'Other ID'],
        // The goods/services code when an item carries none, and the unit code fallback.
        'goods_code' => '000000',
        'service_code' => '000000',
        'unit_code' => 'UM.0018',
        // The ID TKU suffix for a head office (NPWP + 6 digits).
        'idtku_suffix' => '000000',
        // The VAT rate printed on each line.
        'vat_rate' => 12,
    ],

    'legacy' => [
        'kd_jenis_transaksi' => '01',
        'fg_pengganti' => '0',
        // FG_UANG_MUKA on an invoice that deducts taxed down payments: 2 marks the settlement (pelunasan), with the
        // down payments' DPP and VAT in UANG_MUKA_DPP / UANG_MUKA_PPN and the rest in JUMLAH_DPP / JUMLAH_PPN.
        // To be confirmed by the accountant.
        'fg_uang_muka_pelunasan' => '2',
        'header' => ['FK', 'KD_JENIS_TRANSAKSI', 'FG_PENGGANTI', 'NOMOR_FAKTUR', 'MASA_PAJAK', 'TAHUN_PAJAK', 'TANGGAL_FAKTUR', 'NPWP', 'NAMA', 'ALAMAT_LENGKAP', 'JUMLAH_DPP', 'JUMLAH_PPN', 'JUMLAH_PPNBM', 'ID_KETERANGAN_TAMBAHAN', 'FG_UANG_MUKA', 'UANG_MUKA_DPP', 'UANG_MUKA_PPN', 'UANG_MUKA_PPNBM', 'REFERENSI'],
        'lt' => ['LT', 'NPWP', 'NAMA', 'JALAN', 'BLOK', 'NOMOR', 'RT', 'RW', 'KECAMATAN', 'KELURAHAN', 'KABUPATEN', 'PROPINSI', 'KODE_POS', 'NOMOR_TELEPON'],
        'of' => ['OF', 'KODE_OBJEK', 'NAMA', 'HARGA_SATUAN', 'JUMLAH_BARANG', 'HARGA_TOTAL', 'DISKON', 'DPP', 'PPN', 'TARIF_PPNBM', 'PPNBM'],
    ],
    /*
     * Income tax Art. 21 on employees (PP 58/2023, PMK 168/2023; brackets of
     * Art. 17 UU PPh as amended by UU 7/2021 HPP). Every month but the last
     * withholds the monthly average effective rate (TER) of the employee's
     * category on that month's gross; the last month of the year (or of the
     * employment) works out the year's tax on the Art. 17 brackets and
     * withholds the rest. Figures to be confirmed with the accountant.
     */
    'pph21' => [
        'source' => 'PP 58/2023 Lampiran A-C; PMK 168/2023; UU 7/2021 (HPP)',
        // PTKP status at the start of the year → TER category.
        'ter_category' => ['TK/0' => 'A', 'TK/1' => 'A', 'K/0' => 'A', 'TK/2' => 'B', 'TK/3' => 'B', 'K/1' => 'B', 'K/2' => 'B', 'K/3' => 'C'],
        // Monthly TER: [gross up to (Rp, inclusive), rate %]; null is "above the last bound".
        'ter' => [
            'A' => [
                [5_400_000, '0'], [5_650_000, '0.25'], [5_950_000, '0.5'], [6_300_000, '0.75'], [6_750_000, '1'], [7_500_000, '1.25'], [8_550_000, '1.5'],
                [9_650_000, '1.75'], [10_050_000, '2'], [10_350_000, '2.25'], [10_700_000, '2.5'], [11_050_000, '3'], [11_600_000, '3.5'], [12_500_000, '4'],
                [13_750_000, '5'], [15_100_000, '6'], [16_950_000, '7'], [19_750_000, '8'], [24_150_000, '9'], [26_450_000, '10'], [28_000_000, '11'],
                [30_050_000, '12'], [32_400_000, '13'], [35_400_000, '14'], [39_100_000, '15'], [43_850_000, '16'], [47_800_000, '17'], [51_400_000, '18'],
                [56_300_000, '19'], [62_200_000, '20'], [68_600_000, '21'], [77_500_000, '22'], [89_000_000, '23'], [103_000_000, '24'], [125_000_000, '25'],
                [157_000_000, '26'], [206_000_000, '27'], [337_000_000, '28'], [454_000_000, '29'], [550_000_000, '30'], [695_000_000, '31'], [910_000_000, '32'],
                [1_400_000_000, '33'], [null, '34'],
            ],
            'B' => [
                [6_200_000, '0'], [6_500_000, '0.25'], [6_850_000, '0.5'], [7_300_000, '0.75'], [9_200_000, '1'], [10_750_000, '1.5'], [11_250_000, '2'],
                [11_600_000, '2.5'], [12_600_000, '3'], [13_600_000, '4'], [14_950_000, '5'], [16_400_000, '6'], [18_450_000, '7'], [21_850_000, '8'],
                [26_000_000, '9'], [27_700_000, '10'], [29_350_000, '11'], [31_450_000, '12'], [33_950_000, '13'], [37_100_000, '14'], [41_100_000, '15'],
                [45_800_000, '16'], [49_500_000, '17'], [53_800_000, '18'], [58_500_000, '19'], [64_000_000, '20'], [71_000_000, '21'], [80_000_000, '22'],
                [93_000_000, '23'], [109_000_000, '24'], [129_000_000, '25'], [163_000_000, '26'], [211_000_000, '27'], [374_000_000, '28'], [459_000_000, '29'],
                [555_000_000, '30'], [704_000_000, '31'], [957_000_000, '32'], [1_405_000_000, '33'], [null, '34'],
            ],
            'C' => [
                [6_600_000, '0'], [6_950_000, '0.25'], [7_350_000, '0.5'], [7_800_000, '0.75'], [8_850_000, '1'], [9_800_000, '1.25'], [10_950_000, '1.5'],
                [11_200_000, '1.75'], [12_050_000, '2'], [12_950_000, '3'], [14_150_000, '4'], [15_550_000, '5'], [17_050_000, '6'], [19_500_000, '7'],
                [22_700_000, '8'], [26_600_000, '9'], [28_100_000, '10'], [30_100_000, '11'], [32_600_000, '12'], [35_400_000, '13'], [38_900_000, '14'],
                [43_000_000, '15'], [47_400_000, '16'], [51_200_000, '17'], [55_800_000, '18'], [60_400_000, '19'], [66_700_000, '20'], [74_500_000, '21'],
                [83_200_000, '22'], [95_600_000, '23'], [110_000_000, '24'], [134_000_000, '25'], [169_000_000, '26'], [221_000_000, '27'], [390_000_000, '28'],
                [463_000_000, '29'], [561_000_000, '30'], [709_000_000, '31'], [965_000_000, '32'], [1_419_000_000, '33'], [null, '34'],
            ],
        ],
        // PTKP a year: the taxpayer, being married, each dependant (at most three).
        'ptkp' => ['self' => 54_000_000, 'married' => 4_500_000, 'dependant' => 4_500_000, 'max_dependants' => 3],
        // Occupational cost: a share of gross, capped per month worked (6,000,000 a year).
        'biaya_jabatan' => ['percent' => '5', 'monthly_cap' => 500_000],
        // Art. 17 brackets on taxable income (rounded down to the thousand): [up to, rate %].
        'brackets' => [[60_000_000, '5'], [250_000_000, '15'], [500_000_000, '25'], [5_000_000_000, '30'], [null, '35']],
        // The tax object code of a permanent employee's pay.
        'object_code' => '21-100-01',
    ],

    /*
     * The Coretax bulk-import files for the Art. 21 slips: the monthly slips
     * of permanent employees (BPMP) and the annual A1. Element names follow
     * the tax office's templates as known when this was written; the
     * templates could not be fetched to check them, so test-import a file in
     * Coretax before relying on it, and correct a name here if it differs.
     */
    'coretax_pph21' => [
        'bpmp' => ['root' => 'MmPayrollBulk', 'list' => 'ListOfMmPayroll', 'record' => 'MmPayroll', 'fields' => [
            'TaxPeriodMonth' => 'month', 'TaxPeriodYear' => 'year', 'CounterpartOpt' => 'counterpart_opt', 'CounterpartPassport' => 'passport',
            'CounterpartTin' => 'tin', 'StatusTaxExemption' => 'ptkp', 'Position' => 'position', 'TaxCertificate' => 'certificate',
            'TaxObjectCode' => 'object_code', 'Gross' => 'gross', 'Rate' => 'rate', 'IDPlaceOfBusinessActivity' => 'idtku', 'WithholdingDate' => 'date',
        ]],
        'a1' => ['root' => 'A1Bulk', 'list' => 'ListOfA1', 'record' => 'A1', 'fields' => [
            'WorkForSecondEmployer' => 'second_employer', 'TaxPeriodMonthStart' => 'month_start', 'TaxPeriodMonthEnd' => 'month_end', 'TaxPeriodYear' => 'year',
            'CounterpartOpt' => 'counterpart_opt', 'CounterpartPassport' => 'passport', 'CounterpartTin' => 'tin', 'StatusTaxExemption' => 'ptkp',
            'Position' => 'position', 'TaxObjectCode' => 'object_code', 'NumberOfMonths' => 'months', 'SalaryPensionJhtTht' => 'salary',
            'GrossUpOpt' => 'gross_up', 'IncomeTaxBenefit' => 'tax_allowance', 'OtherBenefit' => 'other_allowance', 'Honorarium' => 'honorarium',
            'InsurancePaidByEmployer' => 'insurance', 'Natura' => 'in_kind', 'TantiemBonusThr' => 'bonus', 'PensionContributionJhtThtFee' => 'pension',
            'Zakat' => 'zakat', 'PrevWhTaxSlip' => 'previous_slip', 'TaxCertificate' => 'certificate', 'Article21IncomeTax' => 'tax',
            'IDPlaceOfBusinessActivity' => 'idtku', 'WithholdingDate' => 'date',
        ]],
        'counterpart_resident' => 'Resident',
        'counterpart_foreign' => 'Foreign',
        'certificate' => 'N/A',
    ],
];
