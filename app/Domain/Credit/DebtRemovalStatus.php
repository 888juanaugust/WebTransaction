<?php

declare(strict_types=1);

namespace App\Domain\Credit;

/**
 * A debt-removal claim's life: proposed by marketing, decided by finance.
 *
 * Two terminal states and no way back from either. A wrong approval is
 * corrected the way every posted payment is corrected — a reversing entry in
 * the payment ledger — never by re-opening the claim, and a rejected claim is
 * corrected by filing a new one.
 */
enum DebtRemovalStatus: string
{
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu verifikasi',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Diajukan => 'warning',
            self::Disetujui => 'success',
            self::Ditolak => 'danger',
        };
    }
}
