<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

/**
 * A sales expense claim's life: filed by the sales, decided by finance.
 * Both terminal states stay terminal — a wrong approval is corrected by
 * reversing the posted expense, a rejection by filing a corrected claim.
 */
enum ClaimStatus: string
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
