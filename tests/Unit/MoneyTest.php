<?php

namespace Tests\Unit;

use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_mul_div_rounds_half_up_on_integers(): void
    {
        $this->assertSame(917, Money::mulDiv(1000, 11, 12)); // 916.67
        $this->assertSame(1, Money::mulDiv(1, 1, 2));          // 0.5 → 1
        $this->assertSame(-1, Money::mulDiv(-1, 1, 2));        // -0.5 → -1, away from zero
        $this->assertSame(2, Money::mulDiv(5, 1, 3));          // 1.67 → 2
        $this->assertSame(100_000_000_000, Money::mulDiv(1_000_000_000_000, 1, 10));
        // A product past 64 bits: Rp 50 billion × Rp 40 billion ÷ Rp 80 billion.
        $this->assertSame(25_000_000_000, Money::mulDiv(50_000_000_000, 40_000_000_000, 80_000_000_000));
        $this->assertSame(-1, Money::mulDiv(1, 1, -2), 'a negative denominator rounds away from zero too');
    }

    public function test_percent_takes_decimal_strings_and_rounds_half_up(): void
    {
        $this->assertSame(120, Money::percent(1000, '12'));
        $this->assertSame(125, Money::percent(1000, '12.5'));
        $this->assertSame(125, Money::percent(1000, '12,5'));
        $this->assertSame(3, Money::percent(1000, '0.25'));   // 2.5 → 3
        $this->assertSame(1250, Money::toBasisPoints('12.5'));
        $this->assertSame(1200, Money::toBasisPoints(12));
    }

    public function test_allocate_sums_back_to_the_amount_exactly(): void
    {
        foreach ([[100, [1, 1, 1]], [1001, [3, 3, 3]], [7, [5, 5]], [1, [1, 1, 1]], [999, [250, 250, 499]]] as [$amount, $weights]) {
            $parts = Money::allocate($amount, $weights);
            $this->assertSame($amount, array_sum($parts), json_encode([$amount, $weights, $parts]));
            $this->assertCount(count($weights), $parts);
        }
    }

    public function test_allocate_gives_remainders_to_the_largest_fractions_first_and_ties_to_the_earlier_part(): void
    {
        $this->assertSame([34, 33, 33], Money::allocate(100, [1, 1, 1]));
        $this->assertSame(['a' => 50, 'b' => 50], Money::allocate(100, ['a' => 1, 'b' => 1]));
        $this->assertSame([0, 7], Money::allocate(7, [0, 1]));
        $this->assertSame([-34, -33, -33], Money::allocate(-100, [1, 1, 1]), 'a negative amount splits as its magnitude does');
        $this->assertSame([-1, -2], Money::allocate(-3, [40, 60]));
        $this->assertSame([5_000_000_000, 15_000_000_000], Money::allocate(20_000_000_000, [2_500_000_000, 7_500_000_000]), 'products past 64 bits');
    }

    public function test_format_and_parse_follow_the_indonesian_convention(): void
    {
        $this->assertSame('18.450.000', Money::format(18_450_000));
        $this->assertSame('-1.500', Money::format(-1500));
        $this->assertSame('Rp 18.450.000', Money::rupiah(18_450_000));
        $this->assertSame(18_450_000, Money::parse('18.450.000'));
        $this->assertSame(18_450_000, Money::parse('Rp 18.450.000'));
        $this->assertSame(18_450_000, Money::parse('18450000'));
        $this->assertSame(18_450_001, Money::parse('18.450.000,50'));
        $this->assertSame(-250, Money::parse('-250'));
        $this->assertSame(0, Money::parse(''));
        $this->assertSame(0, Money::parse(null));
    }
}
