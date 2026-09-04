<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * One line of the uploaded customer file, after reading but before writing.
 *
 * A row knows what it would do and why, which is what the preview screen
 * renders. Nothing here touches the database: the decision to write is taken
 * once, by a person, after seeing every one of these.
 */
final class CompanyImportRow
{
    public const BARU = 'baru';

    public const PERBARUI = 'perbarui';

    public const TERTAHAN = 'tertahan';

    /**
     * @param  array<string, mixed>  $nilai  the fields this row would write
     * @param  list<string>  $alasan  why it is held back, if it is
     * @param  list<string>  $catatan  things worth knowing that do not block
     */
    public function __construct(
        public readonly int $baris,
        public readonly string $kode,
        public readonly string $nama,
        public readonly string $status,
        public readonly array $nilai = [],
        public readonly array $alasan = [],
        public readonly array $catatan = [],
    ) {}

    public function tertahan(): bool
    {
        return $this->status === self::TERTAHAN;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::BARU => 'Baru',
            self::PERBARUI => 'Diperbarui',
            default => 'Tertahan',
        };
    }
}
