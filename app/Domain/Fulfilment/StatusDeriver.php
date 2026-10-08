<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * A document's fulfilment status from its lines: pending while nothing has
 * been processed, partial while some has, processed when all of it has, and
 * closed when it was closed by hand. Derived, never stored by a controller.
 */
final class StatusDeriver
{
    public const PENDING = 'pending';

    public const PARTIAL = 'partial';

    public const PROCESSED = 'processed';

    public const CLOSED = 'closed';

    /** @param  Collection<int, object{base_quantity: mixed, processed_quantity: mixed}>  $lines */
    public static function derive(Collection $lines, bool $closed = false): string
    {
        if ($closed) {
            return self::CLOSED;
        }
        if ($lines->isEmpty()) {
            return self::PENDING;
        }

        $ordered = BigDecimal::zero();
        $processed = BigDecimal::zero();
        foreach ($lines as $line) {
            $ordered = $ordered->plus((string) $line->base_quantity);
            $processed = $processed->plus((string) $line->processed_quantity);
        }

        if ($processed->isZero()) {
            return self::PENDING;
        }

        return $processed->isGreaterThanOrEqualTo($ordered) ? self::PROCESSED : self::PARTIAL;
    }
}
