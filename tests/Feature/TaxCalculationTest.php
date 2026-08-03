<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tax\TaxCalculator;
use Tests\TestCase;

/**
 * PPN under PMK 131/2024.
 *
 * The headline rate is 12% but the DPP is 11/12 of harga jual, so the burden
 * on ordinary goods stays at 11%. Getting this wrong overcharges every
 * customer by ~1% and puts wrong numbers on a faktur pajak, so it is pinned
 * down here in detail.
 */
class TaxCalculationTest extends TestCase
{
    private function calculator(): TaxCalculator
    {
        return TaxCalculator::fromConfig();
    }

    public function test_dpp_is_eleven_twelfths_of_harga_jual(): void
    {
        $result = $this->calculator()->forLine(1_200_000);

        // 11/12 x 1.200.000 = exactly 1.100.000
        $this->assertSame(1_100_000, $result->dpp);
    }

    public function test_ppn_is_twelve_percent_of_the_dpp(): void
    {
        $result = $this->calculator()->forLine(1_200_000);

        // 12% of 1.100.000
        $this->assertSame(132_000, $result->ppn);
    }

    public function test_effective_burden_is_eleven_percent_not_twelve(): void
    {
        $result = $this->calculator()->forLine(1_200_000);

        $this->assertSame(1100, $result->effectiveRateBps());
        $this->assertSame(132_000, $result->ppn);

        // The whole point: a naive 12% would have charged 144.000.
        $this->assertNotSame(144_000, $result->ppn);
    }

    public function test_total_is_harga_jual_plus_ppn_not_dpp_plus_ppn(): void
    {
        $result = $this->calculator()->forLine(1_200_000);

        // DPP is a reporting figure. The customer pays the selling price + PPN.
        $this->assertSame(1_332_000, $result->total());
    }

    public function test_rounding_is_half_up_and_stays_integer(): void
    {
        // 11/12 x 1.000.000 = 916.666,67 → 916.667
        $result = $this->calculator()->forLine(1_000_000);

        $this->assertSame(916_667, $result->dpp);
        $this->assertIsInt($result->dpp);

        // 12% of 916.667 = 110.000,04 → 110.000
        $this->assertSame(110_000, $result->ppn);
        $this->assertIsInt($result->ppn);
    }

    public function test_awkward_amounts_never_produce_fractional_rupiah(): void
    {
        foreach ([1, 7, 33, 12_345, 999_999, 1_234_567, 87_654_321] as $harga) {
            $result = $this->calculator()->forLine($harga);

            $this->assertIsInt($result->dpp, "DPP for {$harga} must be an integer");
            $this->assertIsInt($result->ppn, "PPN for {$harga} must be an integer");
            $this->assertSame($harga + $result->ppn, $result->total());
        }
    }

    public function test_zero_line_produces_zero_tax(): void
    {
        $result = $this->calculator()->forLine(0);

        $this->assertSame(0, $result->dpp);
        $this->assertSame(0, $result->ppn);
        $this->assertSame(0, $result->effectiveRateBps());
    }

    public function test_transaction_code_is_04_for_ordinary_goods(): void
    {
        // Code 01 would be the wrong Coretax code for DPP Nilai Lain.
        $this->assertSame('04', $this->calculator()->forLine(100_000)->kodeTransaksi);
    }

    /**
     * Tax is computed per line and summed, never computed on the order total.
     * These give different answers, and the faktur must match the lines
     * printed on it.
     */
    public function test_order_total_is_the_sum_of_per_line_tax(): void
    {
        $calculator = $this->calculator();

        $lines = [
            $calculator->forLine(333_333),
            $calculator->forLine(333_333),
            $calculator->forLine(333_334),
        ];

        $summed = $calculator->sum($lines);

        $this->assertSame(1_000_000, $summed->hargaJual);
        $this->assertSame(
            $lines[0]->dpp + $lines[1]->dpp + $lines[2]->dpp,
            $summed->dpp,
        );
        $this->assertSame(
            $lines[0]->ppn + $lines[1]->ppn + $lines[2]->ppn,
            $summed->ppn,
        );
    }

    public function test_summing_lines_differs_from_taxing_the_total(): void
    {
        $calculator = $this->calculator();

        // Three lines of 33.333 each round down; the same 99.999 taxed as one
        // amount rounds up. A rupiah of drift, and the faktur has to agree
        // with the lines printed on it — which is why the rule is per line.
        $lines = array_map(fn () => $calculator->forLine(33_333), range(1, 3));
        $summed = $calculator->sum($lines);
        $fromTotal = $calculator->forLine(99_999);

        $this->assertSame(99_999, $summed->hargaJual);
        $this->assertSame($summed->hargaJual, $fromTotal->hargaJual);

        $this->assertSame(91_665, $summed->dpp);      // 30.555 x 3
        $this->assertSame(91_666, $fromTotal->dpp);   // round(91.665,75)
        $this->assertNotSame($summed->dpp, $fromTotal->dpp);
    }
}
