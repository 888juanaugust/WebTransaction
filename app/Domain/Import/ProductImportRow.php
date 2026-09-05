<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * One line of the uploaded item file, after reading but before writing.
 *
 * The same shape as `CompanyImportRow` on purpose: the preview screen, the
 * three statuses and the held-back rule are one idea, and a reader who has
 * understood the customer import should not have to learn a second one.
 */
final class ProductImportRow
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
