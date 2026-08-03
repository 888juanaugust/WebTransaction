<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Money;
use App\Models\OrderLine;

/**
 * PPN under PMK 131/2024.
 *
 * The headline rate is 12%, but for ordinary non-luxury goods the DPP is
 * 11/12 of the selling price ("DPP Nilai Lain"), so the effective burden stays
 * at 11%. In Coretax that is transaction code 04, not 01.
 *
 * DPP and PPN are computed and stored *per line item*, never only on the order
 * total: the faktur reports per line, and summing rounded lines is not the
 * same number as rounding a summed total.
 *
 * Do not change any of this without confirming with the accountant.
 */
class TaxCalculator
{
    public function __construct(
        private readonly int $ppnRateBps,
        private readonly int $dppNumerator,
        private readonly int $dppDenominator,
        private readonly string $kodeTransaksi,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            ppnRateBps: (int) config('pajak.ppn_rate_bps'),
            dppNumerator: (int) config('pajak.dpp_factor.numerator'),
            dppDenominator: (int) config('pajak.dpp_factor.denominator'),
            kodeTransaksi: (string) config('pajak.kode_transaksi'),
        );
    }

    /**
     * @param  int  $hargaJual  Selling price for the line, after discount, in rupiah.
     */
    public function forLine(int $hargaJual): TaxBreakdown
    {
        $dpp = Money::mulDiv($hargaJual, $this->dppNumerator, $this->dppDenominator);
        $ppn = Money::mulDiv($dpp, $this->ppnRateBps, 10_000);

        return new TaxBreakdown($hargaJual, $dpp, $ppn, $this->kodeTransaksi);
    }

    /**
     * Sum per-line breakdowns into an order total.
     *
     * This adds up the already-rounded line figures rather than recomputing
     * from the order subtotal, so the invoice total always equals the sum of
     * the lines printed on it.
     *
     * @param  iterable<TaxBreakdown>  $lines
     */
    public function sum(iterable $lines): TaxBreakdown
    {
        $hargaJual = 0;
        $dpp = 0;
        $ppn = 0;

        foreach ($lines as $line) {
            $hargaJual += $line->hargaJual;
            $dpp += $line->dpp;
            $ppn += $line->ppn;
        }

        return new TaxBreakdown($hargaJual, $dpp, $ppn, $this->kodeTransaksi);
    }

    /**
     * Recompute an order's tax totals from its stored line snapshots.
     *
     * Reads the snapshot columns, never the live price list.
     *
     * @param  iterable<OrderLine>  $lines
     */
    public function forStoredLines(iterable $lines): TaxBreakdown
    {
        $breakdowns = [];

        foreach ($lines as $line) {
            $breakdowns[] = new TaxBreakdown(
                hargaJual: (int) $line->line_total_rupiah,
                dpp: (int) $line->dpp_rupiah,
                ppn: (int) $line->ppn_rupiah,
                kodeTransaksi: $this->kodeTransaksi,
            );
        }

        return $this->sum($breakdowns);
    }

    public function kodeTransaksi(): string
    {
        return $this->kodeTransaksi;
    }
}
