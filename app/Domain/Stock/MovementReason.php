<?php

declare(strict_types=1);

namespace App\Domain\Stock;

enum MovementReason: string
{
    case Penerimaan = 'penerimaan';
    case Pengiriman = 'pengiriman';
    case Koreksi = 'koreksi';
    case Retur = 'retur';
    case Opname = 'opname';
    case TransferMasuk = 'transfer_masuk';
    case TransferKeluar = 'transfer_keluar';

    public function label(): string
    {
        return match ($this) {
            self::Penerimaan => 'Penerimaan barang',
            self::Pengiriman => 'Pengiriman',
            self::Koreksi => 'Koreksi',
            self::Retur => 'Retur',
            self::Opname => 'Stok opname',
            self::TransferMasuk => 'Transfer masuk',
            self::TransferKeluar => 'Transfer keluar',
        };
    }
}
