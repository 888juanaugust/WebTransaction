<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * Whether goods came back, or only money moved.
 *
 * The distinction is not cosmetic: a retur puts stock on the shelf and reverses
 * cost of sales, a potongan does neither. Getting it wrong either invents
 * inventory that does not exist or gives away margin that was never lost.
 */
enum CreditNoteType: string
{
    case ReturBarang = 'retur_barang';
    case Potongan = 'potongan';

    public function label(): string
    {
        return match ($this) {
            self::ReturBarang => 'Retur barang',
            self::Potongan => 'Potongan harga',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ReturBarang => 'Barang dikembalikan dan masuk kembali ke gudang.',
            self::Potongan => 'Tidak ada barang kembali — hanya koreksi nilai tagihan.',
        };
    }

    /** Only a retur touches stock, and only a retur needs a warehouse. */
    public function movesStock(): bool
    {
        return $this === self::ReturBarang;
    }
}
