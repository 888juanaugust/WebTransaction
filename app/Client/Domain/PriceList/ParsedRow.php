<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

/** One data row as the parser read it, with the issues it found: blockers keep it out of the list, notes travel with it. */
final class ParsedRow
{
    public const OK = 'ok';

    public const NOTE = 'note';

    public const BLOCKER = 'blocker';

    /** @var list<array{code: string, message: string, severity: string}> */
    public array $issues = [];

    public function __construct(
        public ?string $sheet,
        public int $rowNumber,
        public array $raw,
        public ?string $kode = null,
        public ?string $merk = null,
        public ?string $kategori = null,
        public ?string $tipeProduk = null,
        public ?string $mobil = null,
        public ?string $partNumber = null,
        public ?string $description = null,
        public ?int $qtyPerCtn = null,
        public ?string $satuanDasar = null,
        public ?int $harga = null,
        public bool $aktif = true,
        public ?string $catatan = null,
    ) {}

    public function blocker(string $code, string $message): void
    {
        $this->issues[] = ['code' => $code, 'message' => $message, 'severity' => self::BLOCKER];
    }

    public function note(string $code, string $message): void
    {
        $this->issues[] = ['code' => $code, 'message' => $message, 'severity' => self::NOTE];
    }

    public function status(): string
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === self::BLOCKER) {
                return self::BLOCKER;
            }
        }

        return $this->issues === [] ? self::OK : self::NOTE;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_column($this->issues, 'code');
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    /** The catatan the row carries into the list: what the file said, then every note. */
    public function catatanWithNotes(): ?string
    {
        $parts = array_values(array_filter([$this->catatan, ...array_map(fn (array $i) => $i['message'], array_filter($this->issues, fn (array $i) => $i['severity'] === self::NOTE))]));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @return array<string, mixed> the staging row's columns */
    public function toAttributes(): array
    {
        return [
            'sheet' => $this->sheet,
            'row_number' => $this->rowNumber,
            'raw' => $this->raw,
            'kode' => $this->kode,
            'merk' => $this->merk,
            'kategori' => $this->kategori,
            'tipe_produk' => $this->tipeProduk,
            'mobil' => $this->mobil,
            'part_number' => $this->partNumber,
            'description' => $this->description,
            'qty_per_ctn' => $this->qtyPerCtn,
            'satuan_dasar' => $this->satuanDasar,
            'harga' => $this->harga,
            'aktif' => $this->aktif,
            'catatan' => $this->catatanWithNotes(),
            'status' => $this->status(),
            'issues' => $this->issues,
        ];
    }
}
