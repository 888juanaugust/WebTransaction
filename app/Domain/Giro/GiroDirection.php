<?php

declare(strict_types=1);

namespace App\Domain\Giro;

use App\Domain\Accounting\AccountCode;

/**
 * Which way the paper is pointing.
 *
 * Everything about a giro's life is the same in both directions — it is
 * outstanding, then it clears or bounces or comes back — so the difference is
 * confined here: which counterparty it belongs to, which account it parks in,
 * and which ordinary account it came out of.
 */
enum GiroDirection: string
{
    /** A customer gave us a giro. An asset we are waiting to collect. */
    case Masuk = 'masuk';

    /** We gave a supplier a giro. A liability dated for a day we must fund. */
    case Keluar = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Giro masuk',
            self::Keluar => 'Giro keluar',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Masuk => 'Bilyet giro dari pelanggan. Belum uang sampai cair, '
                .'dan plafon kredit pelanggan belum kembali.',
            self::Keluar => 'Bilyet giro yang kita serahkan ke pemasok. Uangnya masih '
                .'di bank tapi sudah terikat tanggal.',
        };
    }

    /** Where the giro parks while it is outstanding. */
    public function giroAccount(): string
    {
        return match ($this) {
            self::Masuk => AccountCode::PIUTANG_GIRO,
            self::Keluar => AccountCode::UTANG_GIRO,
        };
    }

    /** The ordinary account the balance came out of, and goes back to. */
    public function ordinaryAccount(): string
    {
        return match ($this) {
            self::Masuk => AccountCode::PIUTANG_USAHA,
            self::Keluar => AccountCode::UTANG_USAHA,
        };
    }

    /**
     * Whether receiving it debits the giro account.
     *
     * A giro masuk moves an asset sideways: Dr Piutang Giro / Cr Piutang
     * Usaha. A giro keluar moves a liability the other way: Dr Utang Usaha /
     * Cr Utang Giro. One rule, read in two directions, rather than two rules
     * that can drift apart.
     */
    public function debitsGiroAccountOnIssue(): bool
    {
        return $this === self::Masuk;
    }
}
