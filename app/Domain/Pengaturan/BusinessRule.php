<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

/**
 * The behaviours a company switches on or off on the Business Rules tab of
 * Preferences. Read them through isOn(), never hard-code them; the day
 * counts of the credit rules are read through CreditCheck.
 */
enum BusinessRule
{
    case SalesOrderApproval;
    case SegregationOfDuties;
    case AllowNegativeStock;

    public function key(): PreferensiKey
    {
        return match ($this) {
            self::SalesOrderApproval => PreferensiKey::SalesOrderApproval,
            self::SegregationOfDuties => PreferensiKey::SegregationOfDuties,
            self::AllowNegativeStock => PreferensiKey::AllowNegativeStock,
        };
    }

    public function isOn(): bool
    {
        return (bool) app(Preferensi::class)->get($this->key());
    }
}
