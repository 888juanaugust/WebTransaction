<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Terbilang;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The amount in words, which every Indonesian invoice carries under the figures.
 *
 * It reads like decoration and is not: it is the line a customer's bookkeeper
 * checks the digits against, so a wrong word here is a dispute about money. The
 * cases below are the ones a naive "spell each digit group" implementation gets
 * wrong — the irregular run below twelve, and the "se-" prefixes that replace
 * "satu" in front of puluh, belas, ratus and ribu but *not* in front of juta.
 */
class TerbilangTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function numbers(): array
    {
        return [
            // The irregular run. "satu belas" and "satu puluh" are not words.
            'nol' => [0, 'nol'],
            'satu' => [1, 'satu'],
            'sepuluh' => [10, 'sepuluh'],
            'sebelas' => [11, 'sebelas'],
            'dua belas' => [12, 'dua belas'],
            'sembilan belas' => [19, 'sembilan belas'],

            // Tens: the multiplier is spoken, the unit only when non-zero.
            'dua puluh' => [20, 'dua puluh'],
            'dua puluh satu' => [21, 'dua puluh satu'],
            'sembilan puluh sembilan' => [99, 'sembilan puluh sembilan'],

            // Hundreds: "seratus", never "satu ratus" — but "dua ratus".
            'seratus' => [100, 'seratus'],
            'seratus satu' => [101, 'seratus satu'],
            'seratus sepuluh' => [110, 'seratus sepuluh'],
            'seratus lima belas' => [115, 'seratus lima belas'],
            'dua ratus' => [200, 'dua ratus'],
            'sembilan ratus sembilan puluh sembilan' => [999, 'sembilan ratus sembilan puluh sembilan'],

            // Thousands: same "se-" rule, and it must not leak past 1.999.
            'seribu' => [1_000, 'seribu'],
            'seribu satu' => [1_001, 'seribu satu'],
            'seribu seratus' => [1_100, 'seribu seratus'],
            'dua ribu' => [2_000, 'dua ribu'],
            'sepuluh ribu' => [10_000, 'sepuluh ribu'],
            'seratus ribu' => [100_000, 'seratus ribu'],
            'seratus lima puluh ribu' => [150_000, 'seratus lima puluh ribu'],

            // Millions and up take "satu", not "se-".
            'satu juta' => [1_000_000, 'satu juta'],
            'satu juta dua ratus lima puluh ribu' => [1_250_000, 'satu juta dua ratus lima puluh ribu'],
            'satu miliar' => [1_000_000_000, 'satu miliar'],
            'satu triliun' => [1_000_000_000_000, 'satu triliun'],

            // A total with a gap in the middle: the empty thousands group must
            // not produce a stray "nol".
            'dua juta lima ratus' => [2_000_500, 'dua juta lima ratus'],

            // The shape of a real invoice total: an order of a few cartons.
            'faktur' => [
                14_872_500,
                'empat belas juta delapan ratus tujuh puluh dua ribu lima ratus',
            ],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_spells_a_number(int $number, string $expected): void
    {
        $this->assertSame($expected, Terbilang::words($number));
    }

    public function test_it_appends_the_currency(): void
    {
        $this->assertSame('satu juta rupiah', Terbilang::rupiah(1_000_000));
        $this->assertSame('nol rupiah', Terbilang::rupiah(0));
    }

    /**
     * A credit note is a negative amount, and it has to say so in words too —
     * silently spelling it as a positive would put the wrong sign on a
     * document that says "terbilang" underneath a bracketed figure.
     */
    public function test_a_negative_amount_is_spoken_as_minus(): void
    {
        $this->assertSame('minus lima ratus ribu rupiah', Terbilang::rupiah(-500_000));
    }

    /**
     * words() is the bare number, with no currency to hang a sign off; the
     * caller has to decide what a negative means before asking for words.
     */
    public function test_words_rejects_a_negative_number(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Terbilang::words(-1);
    }
}
