<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * One line of an uploaded file, after reading but before writing — the
 * shape shared by the staff and opening-balance importers.
 *
 * The same idea as `CompanyImportRow` and `ProductImportRow`: a line is
 * either going to be written or is held back with reasons, and it may carry
 * notes that do not block. Those two importers update on a known key; these
 * three create only — an existing staff account or a document number that
 * already exists is a held row, never an update, because "update" on a
 * person's role or on a debt is an audited act with its own screen.
 */
final class BarisImpor
{
    public const BARU = 'baru';

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

    /** @param list<string> $alasan */
    public static function dari(int $baris, string $kode, string $nama, array $nilai, array $alasan, array $catatan = []): self
    {
        return new self(
            baris: $baris,
            kode: $kode,
            nama: $nama,
            status: $alasan === [] ? self::BARU : self::TERTAHAN,
            nilai: $nilai,
            alasan: $alasan,
            catatan: $catatan,
        );
    }

    public function tertahan(): bool
    {
        return $this->status === self::TERTAHAN;
    }

    public function statusLabel(): string
    {
        return $this->tertahan() ? 'Tertahan' : 'Baru';
    }
}
