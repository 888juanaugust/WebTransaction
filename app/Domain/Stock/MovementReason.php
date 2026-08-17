<?php

declare(strict_types=1);

namespace App\Domain\Stock;

enum MovementReason: string
{
    case Penerimaan = 'penerimaan';
    case Pengiriman = 'pengiriman';
    case Koreksi = 'koreksi';

    /** A customer sending goods back to us. Stock goes up. */
    case Retur = 'retur';

    /**
     * Us sending goods back to a supplier. Stock goes down.
     *
     * Its own reason rather than a Koreksi, because it is not one: a
     * correction says the record was wrong, and this says the goods left. The
     * distinction matters to anybody reading a movement history, and it
     * matters to the reports — a return is not a sale and must not make a part
     * look like it is moving.
     */
    case ReturPembelian = 'retur_pembelian';
    case Opname = 'opname';
    case TransferMasuk = 'transfer_masuk';
    case TransferKeluar = 'transfer_keluar';

    /**
     * Freight or duty landing on goods that are already here.
     *
     * The only reason that carries a value and no quantity. Nothing moves off
     * the shelf; what it cost to get there changes.
     */
    case BiayaPerolehan = 'biaya_perolehan';

    public function label(): string
    {
        return match ($this) {
            self::Penerimaan => 'Penerimaan barang',
            self::Pengiriman => 'Pengiriman',
            self::Koreksi => 'Koreksi',
            self::Retur => 'Retur pelanggan',
            self::ReturPembelian => 'Retur ke pemasok',
            self::Opname => 'Stok opname',
            self::TransferMasuk => 'Transfer masuk',
            self::TransferKeluar => 'Transfer keluar',
            self::BiayaPerolehan => 'Biaya perolehan',
        };
    }
}
