<?php

declare(strict_types=1);

namespace App\Domain\Import;

/** Which example file somebody is asking for. */
enum TemplateKind: string
{
    case Harga = 'harga';
    case Pelanggan = 'pelanggan';

    case Barang = 'barang';

    public function label(): string
    {
        return match ($this) {
            self::Harga => 'Harga & barang',
            self::Pelanggan => 'Pelanggan',
            self::Barang => 'Barang',
        };
    }
}
