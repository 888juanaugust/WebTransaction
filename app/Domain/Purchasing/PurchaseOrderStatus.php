<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

/**
 * draft → dikirim → selesai, with dibatalkan available until goods arrive.
 *
 * `dikirim` locks the lines. From the moment an order goes to a supplier the
 * document records what was agreed, and receiving against a line somebody
 * edited afterwards would compare deliveries to a moving target.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Dikirim = 'dikirim';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Dikirim => 'Dikirim ke pemasok',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Dikirim => 'warning',
            self::Selesai => 'success',
            self::Dibatalkan => 'danger',
        };
    }

    /** Only a sent order can be received against. */
    public function canReceive(): bool
    {
        return $this === self::Dikirim;
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Dikirim, self::Dibatalkan], true),
            self::Dikirim => in_array($next, [self::Selesai, self::Dibatalkan], true),
            // Terminal.
            self::Selesai, self::Dibatalkan => false,
        };
    }
}
