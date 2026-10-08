<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/** Derived from paid_amount against total; never set by hand. */
final class PaymentStatus
{
    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public static function derive(int $total, int $paid): string
    {
        if ($paid <= 0 && $total !== 0) {
            return self::UNPAID;
        }
        if ($total >= 0 ? $paid >= $total : $paid <= $total) {
            return self::PAID;
        }

        return self::PARTIAL;
    }
}
