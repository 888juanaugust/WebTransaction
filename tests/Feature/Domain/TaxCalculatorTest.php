<?php

namespace Tests\Feature\Domain;

use App\Domain\Tax\TaxCalculator;
use App\Models\Company\TaxCode;
use Tests\TestCase;

class TaxCalculatorTest extends TestCase
{
    private function vat12(): TaxCode
    {
        return new TaxCode(['tax_type' => 'vat', 'description' => 'VAT 12%', 'rate_percent' => '12.0000', 'dpp_numerator' => 11, 'dpp_denominator' => 12]);
    }

    public function test_exclusive_vat_12_with_dpp_11_12_burdens_11_percent(): void
    {
        $r = TaxCalculator::forLine(1_000_000, $this->vat12());

        $this->assertSame(1_000_000, $r->base);
        $this->assertSame(916_667, $r->dpp);
        $this->assertSame(110_000, $r->tax);
        $this->assertSame(1_110_000, $r->gross);
    }

    public function test_inclusive_prices_are_split_back_into_base_and_tax(): void
    {
        $r = TaxCalculator::forLine(1_110_000, $this->vat12(), inclusive: true);

        $this->assertSame(1_000_000, $r->base);
        $this->assertSame(110_000, $r->tax);
        $this->assertSame(916_667, $r->dpp);
        $this->assertSame(1_110_000, $r->gross);
    }

    public function test_rounding_is_half_up_per_line(): void
    {
        // 18.450.000 × 11/12 = 16.912.500 exactly; × 12 % = 2.029.500
        $r = TaxCalculator::forLine(18_450_000, $this->vat12());
        $this->assertSame(2_029_500, $r->tax);

        // 12.345 × 11/12 = 11.316,25 → 11.316; × 12 % = 1.357,92 → 1.358
        $r = TaxCalculator::forLine(12_345, $this->vat12());
        $this->assertSame(11_316, $r->dpp);
        $this->assertSame(1_358, $r->tax);

        // 5 × 11/12 = 4,583 → 5; × 12 % = 0,6 → 1
        $r = TaxCalculator::forLine(5, $this->vat12());
        $this->assertSame(1, $r->tax);
    }

    public function test_a_plain_rate_without_a_dpp_fraction_and_a_zero_rate(): void
    {
        $plain = new TaxCode(['tax_type' => 'vat', 'description' => 'VAT 11%', 'rate_percent' => '11.0000', 'dpp_numerator' => 1, 'dpp_denominator' => 1]);
        $r = TaxCalculator::forLine(1_000_000, $plain);
        $this->assertSame(1_000_000, $r->dpp);
        $this->assertSame(110_000, $r->tax);

        $exempt = new TaxCode(['tax_type' => 'vat', 'description' => 'Exempt', 'rate_percent' => '0', 'dpp_numerator' => 1, 'dpp_denominator' => 1]);
        $r = TaxCalculator::forLine(1_000_000, $exempt, inclusive: true);
        $this->assertSame(1_000_000, $r->base);
        $this->assertSame(0, $r->tax);

        $r = TaxCalculator::forLine(1_000_000, null);
        $this->assertSame(1_000_000, $r->gross);
    }

    public function test_the_tax_code_reports_its_effective_rate(): void
    {
        $this->assertSame(1200, $this->vat12()->rateBasisPoints());
        $this->assertEqualsWithDelta(11.0, $this->vat12()->effectiveRatePercent(), 0.0001);
    }
}
