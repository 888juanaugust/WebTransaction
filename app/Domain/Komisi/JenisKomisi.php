<?php

declare(strict_types=1);

namespace App\Domain\Komisi;

/**
 * What a commission is paid on.
 *
 * The first kind is the one the system has always had: a sales or marketing
 * seat earning on the customers it holds. The owner asked for three more
 * (2026-09), and each is a different *basis*, not a different rate on the
 * same one:
 *
 * - **Supervisor** — a cabang's whole collected sales, whoever's customers
 *   they were. Scoped to one region, because that is what a supervisor
 *   supervises. Unlike a seat, they are paid on customers with no sales
 *   assigned too: the shop's money came in on their floor.
 * - **Manajer** — every cabang's collected sales. The same arithmetic with
 *   no scope.
 * - **Pembelian impor** — paid import purchases: the lines of settled
 *   supplier bills whose product is `golongan = impor`, net of PPN. The
 *   person who runs the import book is paid on what it actually cost, once
 *   the supplier has actually been paid — the mirror of paying sellers on
 *   settlement rather than on invoicing.
 *
 * All four follow the one rule that matters: **paid on money that moved,
 * never on paperwork.** An unpaid invoice earns a supervisor nothing; an
 * unpaid import bill earns the purchaser nothing; a balance carried in from
 * the old books (`saldo_awal`) earns nobody anything.
 */
enum JenisKomisi: string
{
    case Penjualan = 'penjualan';
    case Supervisor = 'supervisor';
    case Manajer = 'manajer';
    case PembelianImpor = 'pembelian_impor';

    public function label(): string
    {
        return match ($this) {
            self::Penjualan => 'Penjualan',
            self::Supervisor => 'Supervisor',
            self::Manajer => 'Manajer',
            self::PembelianImpor => 'Pembelian impor',
        };
    }

    /** What the rate is a percentage of, for the screen. */
    public function dasar(): string
    {
        return match ($this) {
            self::Penjualan => 'faktur lunas atas pelanggan yang dipegang, tanpa PPN',
            self::Supervisor => 'seluruh faktur lunas satu cabang, tanpa PPN',
            self::Manajer => 'seluruh faktur lunas semua cabang, tanpa PPN',
            self::PembelianImpor => 'tagihan pemasok lunas untuk barang golongan impor, tanpa PPN',
        };
    }

    /** A supervisor is a supervisor *of somewhere*. */
    public function butuhCabang(): bool
    {
        return $this === self::Supervisor;
    }

    /** The seat kinds: paid through the customer's team, one rate per person. */
    public function perKursi(): bool
    {
        return $this === self::Penjualan;
    }

    /** @return array<string, string> value => label */
    public static function pilihan(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
