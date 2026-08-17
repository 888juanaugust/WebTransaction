<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * What the sales report groups by.
 *
 * Four questions, one query. They are separate because they get asked by
 * different people for different reasons: the owner wants customers, the buyer
 * wants brands, and everyone wants months.
 */
enum SalesDimension: string
{
    case Pelanggan = 'pelanggan';
    case Merk = 'merk';
    case Kategori = 'kategori';
    case Bulan = 'bulan';

    public function label(): string
    {
        return match ($this) {
            self::Pelanggan => 'Pelanggan',
            self::Merk => 'Merk',
            self::Kategori => 'Kategori',
            self::Bulan => 'Bulan',
        };
    }

    public function question(): string
    {
        return match ($this) {
            self::Pelanggan => 'Siapa yang belanja, dan berapa untungnya.',
            self::Merk => 'Merk mana yang jalan.',
            self::Kategori => 'Kategori mana yang jalan.',
            self::Bulan => 'Naik atau turun dari bulan ke bulan.',
        };
    }

    /**
     * Whether a credit note with no order line behind it can be attributed.
     *
     * A **potongan** — a settlement against an invoice as a whole — has no
     * line, so it cannot be assigned to a brand or a category. It can always
     * be assigned to a customer and to a month, because the note knows both.
     * Reports that cannot place it say so in a note rather than dropping it
     * silently, which would make the total disagree with the ledger.
     */
    public function canPlaceUnlinkedCredits(): bool
    {
        return $this === self::Pelanggan || $this === self::Bulan;
    }
}
