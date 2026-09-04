<?php

declare(strict_types=1);

namespace App\Domain\Credit;

/**
 * What came of the conversation.
 *
 * Deliberately includes the outcomes nobody wants to type. "Tidak terhubung"
 * is the commonest result of a collections round and the most useful to
 * record: four unanswered calls is itself the finding, and without a name for
 * it the register shows an invoice nobody has chased when somebody has tried
 * all week.
 */
enum CollectionOutcome: string
{
    case JanjiBayar = 'janji_bayar';
    case MintaTempo = 'minta_tempo';
    case TidakTerhubung = 'tidak_terhubung';
    case Sengketa = 'sengketa';
    case SudahBayar = 'sudah_bayar';

    public function label(): string
    {
        return match ($this) {
            self::JanjiBayar => 'Janji bayar',
            self::MintaTempo => 'Minta perpanjangan',
            self::TidakTerhubung => 'Tidak terhubung',
            self::Sengketa => 'Ada sengketa',
            self::SudahBayar => 'Katanya sudah bayar',
        };
    }

    /** Only one outcome carries a date and an amount. */
    public function butuhJanji(): bool
    {
        return $this === self::JanjiBayar;
    }

    public function warna(): string
    {
        return match ($this) {
            self::JanjiBayar => 'success',
            self::SudahBayar => 'info',
            self::MintaTempo => 'warning',
            self::TidakTerhubung, self::Sengketa => 'danger',
        };
    }
}
