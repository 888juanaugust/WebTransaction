<?php

declare(strict_types=1);

namespace App\Domain\Launch;

enum LaunchCheckKind: string
{
    /** The system worked it out. Nobody can tick or untick it. */
    case Otomatis = 'otomatis';

    /** Somebody said so, with their name against it. Nothing measured it. */
    case Pernyataan = 'pernyataan';

    public function label(): string
    {
        return match ($this) {
            self::Otomatis => 'Diperiksa sistem',
            self::Pernyataan => 'Dinyatakan orang',
        };
    }
}
