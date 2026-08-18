<?php

declare(strict_types=1);

namespace App\Domain\Giro;

/**
 * Where a giro is in its life.
 *
 * ```
 * beredar → cair
 *    ↓  ↘
 *    ↓   ditolak
 * dibatalkan
 * ```
 *
 * Only `cair` moves money. The other two endings unwind the instrument and put
 * the balance back where it came from — which is the same journal entry three
 * times over, so there is one rule for it.
 *
 * **Banking it is not a state.** "Deposited and waiting to hear" is a fact
 * about a giro, recorded as a date, not a stage of its life: nothing has moved
 * and nothing in the books has changed. Modelling it as a state would double
 * the machine to answer a question one nullable column already answers.
 */
enum GiroStatus: string
{
    /** Outstanding: we hold it, or the supplier holds ours. */
    case Beredar = 'beredar';

    /** Cleared. The money actually moved. */
    case Cair = 'cair';

    /** Bounced. The debt comes straight back, and so does the risk. */
    case Ditolak = 'ditolak';

    /**
     * Handed back without ever being banked.
     *
     * The customer settled in cash and took their paper home, or it was
     * written wrong and reissued. Not a failure and not a payment.
     */
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Beredar => 'Beredar',
            self::Cair => 'Cair',
            self::Ditolak => 'Ditolak',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Beredar => 'warning',
            self::Cair => 'success',
            self::Ditolak => 'danger',
            self::Dibatalkan => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Beredar;
    }

    /** Whether this ending unwinds the instrument without money moving. */
    public function releasesWithoutPayment(): bool
    {
        return $this === self::Ditolak || $this === self::Dibatalkan;
    }
}
