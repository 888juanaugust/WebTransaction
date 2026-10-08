<?php

namespace Tests\Feature\Domain;

use App\Domain\Documents\LineCalculator;
use App\Domain\Tax\TaxCalculator;
use App\Models\Company\TaxCode;
use Tests\TestCase;

/** The arithmetic of a priced document: line discounts, the header discount spread, tax per line, charges. */
class LineCalculatorTest extends TestCase
{
    private TaxCode $vat;

    private TaxCode $exempt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->vat = TaxCode::default();
        $this->exempt = TaxCode::query()->where('rate_percent', 0)->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['quantity' => '3', 'unit_price' => '10000', 'discount_percent' => '10', 'discount_amount' => 999, 'tax_code_id' => $this->vat->id], // 27,000: the percentage decides
            ['quantity' => '1.5', 'unit_price' => '1001', 'discount_percent' => '0', 'discount_amount' => 0, 'tax_code_id' => $this->exempt->id], // 1,501.5 → 1,502
            ['quantity' => '2', 'unit_price' => '5000', 'discount_percent' => '', 'discount_amount' => '500', 'tax_code_id' => $this->vat->id], // 9,500
        ];
    }

    public function test_lines_mixed_tax_codes_the_header_discount_and_charges(): void
    {
        $r = LineCalculator::compute($this->lines(), true, false, '10', 0, [['amount' => '5.000']]);

        $this->assertSame([27_000, 1_502, 9_500], array_column($r['lines'], 'amount'));
        $this->assertSame([3_000, 0, 500], array_column($r['lines'], 'discount_amount'));
        $this->assertSame(38_002, $r['subtotal']);
        $this->assertSame(3_800, $r['discount_amount'], '10 % of the subtotal, whole rupiah');
        // 3,800 over 27,000 : 1,502 : 9,500 is 2,699.86 / 150.19 / 949.95: the two left over go to the largest fractions.
        $this->assertSame([2_700, 150, 950], array_column($r['lines'], 'header_discount'));

        // Tax per line, on what the line is worth after its share of the header discount; the exempt line has none.
        foreach ($r['lines'] as $i => $line) {
            $expected = TaxCalculator::forLine($line['amount'] - $line['header_discount'], [$this->vat, $this->exempt, $this->vat][$i], false);
            $this->assertSame([$expected->dpp, $expected->tax], [$line['dpp_amount'], $line['tax_amount']]);
        }
        $this->assertSame(22_275, $r['lines'][0]['dpp_amount'], '11/12 of 24,300');
        $this->assertSame(2_673, $r['lines'][0]['tax_amount']);
        $this->assertSame(0, $r['lines'][1]['tax_amount']);
        $this->assertSame(array_sum(array_column($r['lines'], 'tax_amount')), $r['tax_total']);
        $this->assertSame(array_sum(array_column($r['lines'], 'dpp_amount')), $r['dpp_total']);
        $this->assertSame(5_000, $r['charges_total']);
        $this->assertSame(38_002 - 3_800 + $r['tax_total'] + 5_000, $r['total']);
    }

    public function test_inclusive_prices_hold_their_tax_and_untaxed_documents_have_none(): void
    {
        $inclusive = LineCalculator::compute($this->lines(), true, true);
        $this->assertSame($inclusive['subtotal'], $inclusive['total'], 'the tax is inside the prices');
        $this->assertGreaterThan(0, $inclusive['tax_total']);

        $untaxed = LineCalculator::compute($this->lines(), false, false);
        $this->assertSame(0, $untaxed['tax_total']);
        $this->assertSame($untaxed['subtotal'], $untaxed['total']);

        // A fixed header discount counts only where no percentage is given.
        $this->assertSame(1_000, LineCalculator::compute($this->lines(), false, false, 0, 1_000)['discount_amount']);
        $this->assertSame([0, 0, 0], array_column(LineCalculator::compute($this->lines(), false, false)['lines'], 'header_discount'));
    }
}
