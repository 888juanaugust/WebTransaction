<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * What the sales report groups by.
 *
 * Six questions, one query. They are separate because they get asked by
 * different people for different reasons: the owner wants customers and the
 * standing of each sales seat, the buyer wants brands and which items actually
 * move, and everyone wants months.
 */
enum SalesDimension: string
{
    case Pelanggan = 'pelanggan';
    case Sales = 'sales';
    case Barang = 'barang';
    case Merk = 'merk';
    case Kategori = 'kategori';
    case Golongan = 'golongan';
    case Bulan = 'bulan';

    public function label(): string
    {
        return match ($this) {
            self::Pelanggan => 'Pelanggan',
            self::Sales => 'Sales',
            self::Barang => 'Barang',
            self::Merk => 'Merk',
            self::Kategori => 'Kategori',
            self::Golongan => 'Golongan',
            self::Bulan => 'Bulan',
        };
    }

    public function question(): string
    {
        return match ($this) {
            self::Pelanggan => 'Siapa yang belanja, dan berapa untungnya.',
            self::Sales => 'Berapa omset yang dibukukan tiap sales.',
            self::Barang => 'Barang mana yang paling laku.',
            self::Merk => 'Merk mana yang jalan.',
            self::Kategori => 'Kategori mana yang jalan.',
            self::Golongan => 'Barang impor, titip impor, atau lokal — mana yang jalan.',
            self::Bulan => 'Naik atau turun dari bulan ke bulan.',
        };
    }

    /**
     * Whether the quantity column means anything here.
     *
     * Only per barang. One SKU has one base unit, so its units add up. Summed
     * across a merk they do not: 18 PCS of bearings plus 2 SET of suspension
     * is 20 of nothing, and a "top seller" ranked on that number would be
     * whichever brand happens to sell small parts.
     */
    public function countsUnits(): bool
    {
        return $this === self::Barang;
    }

    /**
     * Whether a credit note with no order line behind it can be attributed.
     *
     * A **potongan** — a settlement against an invoice as a whole — has no
     * line, so it cannot be assigned to a brand, a category or an item. It can
     * always be assigned to a customer, to that customer's sales seat, and to
     * a month, because the note knows all three. Reports that cannot place it
     * say so in a note rather than dropping it silently, which would make the
     * total disagree with the ledger.
     */
    public function canPlaceUnlinkedCredits(): bool
    {
        return in_array($this, [self::Pelanggan, self::Sales, self::Bulan], true);
    }
}
