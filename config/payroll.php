<?php

/*
 * BPJS contributions on wages (Perpres 82/2018 as amended by Perpres
 * 64/2020 for health; PP 44/2015, 45/2015, 46/2015 and their amendments for
 * employment). Rates are % of the wage the contribution counts on (basic
 * salary and fixed allowances); caps are monthly wages. The pension cap is
 * set each March by BPJS Ketenagakerjaan: add the new one here, dated.
 * Figures to be confirmed with the accountant.
 */
return [
    'bpjs' => [
        // Health: employer and employee shares, on wages up to the cap.
        'health' => ['employer' => '4', 'employee' => '1', 'cap' => 12_000_000],
        // Old-age savings (JHT): no cap.
        'jht' => ['employer' => '3.7', 'employee' => '2'],
        // Pension (JP): on wages up to the cap in force on the pay date.
        'jp' => ['employer' => '2', 'employee' => '1', 'caps' => ['2024-03-01' => 10_042_300, '2025-03-01' => 10_547_400, '2026-03-01' => 11_086_300]],
        // Death (JKM); work accident (JKK) is each employee's own rate by the work's risk group.
        'jkm' => ['employer' => '0.3'],
        'jkk_rates' => ['0.24', '0.54', '0.89', '1.27', '1.74'],
        // The income kinds whose amounts make up the wage BPJS counts on.
        'wage_fee_types' => ['salary', 'other_allowance'],
    ],
];
