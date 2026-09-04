<?php

declare(strict_types=1);

namespace App\Domain\Credit;

/** How the shop was reached. */
enum ContactMethod: string
{
    case Telepon = 'telepon';
    case Whatsapp = 'whatsapp';
    case Kunjungan = 'kunjungan';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Telepon => 'Telepon',
            self::Whatsapp => 'WhatsApp',
            self::Kunjungan => 'Kunjungan',
            self::Email => 'Email',
        };
    }
}
