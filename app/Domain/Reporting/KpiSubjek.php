<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * Whose performance the KPI sheet is about.
 *
 * Three different jobs are being judged, so three different sets of numbers.
 * A sales seat is judged on coverage and collection as much as on omset; a
 * toko on how often and how broadly it buys; an item on how many shops take
 * it, not just how much money it made once.
 */
enum KpiSubjek: string
{
    case Sales = 'sales';
    case Toko = 'toko';
    case Barang = 'barang';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Toko => 'Toko',
            self::Barang => 'Barang',
        };
    }

    public function question(): string
    {
        return match ($this) {
            self::Sales => 'Capaian tiap sales: omset, target, cakupan toko, dan tagihannya.',
            self::Toko => 'Toko mana yang rutin, mana yang mulai jarang, dan berapa yang nunggak.',
            self::Barang => 'Barang mana yang ditebus banyak toko, bukan cuma laku sekali besar.',
        };
    }
}
