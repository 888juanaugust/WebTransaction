<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Splitting an amount so the parts add back up to it.
 *
 * This exists because `mulDiv` per row does not. Three thirds of Rp 100 come
 * out as 33 + 33 + 33 and a rupiah goes missing, and the place it goes missing
 * is a clearing account that then never empties — which is worse than being
 * wrong, because it is a check that cries wolf until people stop reading it.
 *
 * So the property being tested throughout is dull and absolute: **the shares
 * sum to the amount.** Everything else is about which row gets the odd rupiah.
 */
class MoneyAllocateTest extends TestCase
{
    public function test_the_shares_always_sum_to_the_amount(): void
    {
        // Deliberately awkward: primes, a large charge over small weights,
        // and weights that do not divide the amount in any convenient way.
        $cases = [
            [100, [1, 1, 1]],
            [1, [1, 1, 1]],
            [7, [1, 2, 4]],
            [1_000_000, [333, 333, 334]],
            [999_999, [7, 11, 13, 17, 19]],
            [2_500_000, [1_450_000, 3_300_000, 17]],
            [83, [1]],
            [1_000_000_000, [1, 999_999_999]],
        ];

        foreach ($cases as [$amount, $weights]) {
            $shares = Money::allocate($amount, $weights);

            $this->assertSame(
                $amount,
                array_sum($shares),
                sprintf('%d over [%s] came to %d', $amount, implode(', ', $weights), array_sum($shares)),
            );
            $this->assertCount(count($weights), $shares);
        }
    }

    public function test_an_even_split_is_even(): void
    {
        $this->assertSame([25, 25, 25, 25], Money::allocate(100, [1, 1, 1, 1]));
    }

    public function test_the_odd_rupiah_goes_to_the_largest_remainder(): void
    {
        /*
         * 10 over weights 1, 1, 1: each is 3.33, so each floors to 3 and one
         * rupiah is left. Every remainder is identical, so the tie-break —
         * earliest row — decides, and it decides the same way every run.
         */
        $this->assertSame([4, 3, 3], Money::allocate(10, [1, 1, 1]));

        /*
         * 10 over weights 1, 2: exact shares 3.33 and 6.67. The second row is
         * robbed harder by flooring, so the rupiah goes there.
         */
        $this->assertSame([3, 7], Money::allocate(10, [1, 2]));
    }

    public function test_the_result_does_not_depend_on_the_order_of_equal_weights(): void
    {
        // Sorting the input must not move a rupiah somewhere else. usort is
        // not stable in PHP, which is why the comparator breaks ties on index.
        $this->assertSame([4, 3, 3], Money::allocate(10, [1, 1, 1]));
        $this->assertSame([2, 2, 2, 2, 1], Money::allocate(9, [1, 1, 1, 1, 1]));
    }

    public function test_a_zero_weight_gets_nothing(): void
    {
        // A receipt line with no value takes no share of a value-based charge.
        $this->assertSame([0, 100], Money::allocate(100, [0, 1]));
        $this->assertSame([50, 0, 50], Money::allocate(100, [1, 0, 1]));
    }

    public function test_all_zero_weights_spread_evenly_rather_than_stranding_the_money(): void
    {
        /*
         * Refusing here would be defensible in the abstract and wrong in
         * practice: the charge exists and has to land somewhere, and throwing
         * would leave it in the clearing account with no way out but a manual
         * journal.
         */
        $this->assertSame([34, 33, 33], Money::allocate(100, [0, 0, 0]));
    }

    public function test_a_negative_amount_spreads_the_same_way_in_reverse(): void
    {
        // A credit note against a freight bill has to undo exactly what the
        // bill did, rupiah for rupiah, or the clearing account keeps a residue.
        $this->assertSame([-4, -3, -3], Money::allocate(-10, [1, 1, 1]));
        $this->assertSame(-10, array_sum(Money::allocate(-10, [1, 1, 1])));

        $forward = Money::allocate(999_999, [7, 11, 13]);
        $back = Money::allocate(-999_999, [7, 11, 13]);

        foreach ($forward as $i => $share) {
            $this->assertSame(-$share, $back[$i]);
        }
    }

    public function test_zero_allocates_to_zero(): void
    {
        $this->assertSame([0, 0], Money::allocate(0, [3, 5]));
    }

    public function test_it_refuses_nonsense(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::allocate(100, []);
    }

    public function test_a_negative_weight_is_refused(): void
    {
        // Not a share of anything, and silently treating it as zero would let
        // a corrupt receipt line quietly redistribute a charge.
        $this->expectException(InvalidArgumentException::class);

        Money::allocate(100, [1, -1]);
    }
}
