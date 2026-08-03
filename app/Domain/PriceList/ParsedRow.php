<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

use App\Models\PriceListImportRow;

/**
 * One candidate price list row, plus whatever the parser found wrong with it.
 *
 * Blockers route the row to the review queue and keep it out of the published
 * version. Notes let the row through, annotated.
 */
final class ParsedRow
{
    /** @var list<array{code: string, message: string}> */
    public array $issues = [];

    public function __construct(
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
        public ?string $sheetName = null,
        public ?int $sourceRowNumber = null,
        public array $raw = [],
    ) {}

    /** Import anyway, annotated. */
    public function note(string $code, string $message): self
    {
        $this->issues[] = ['code' => $code, 'message' => $message, 'severity' => 'note'];

        return $this;
    }

    /** Route to the review queue; never published. */
    public function blocker(string $code, string $message): self
    {
        $this->issues[] = ['code' => $code, 'message' => $message, 'severity' => 'blocker'];

        return $this;
    }

    public function hasBlocker(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'blocker') {
                return true;
            }
        }

        return false;
    }

    public function status(): string
    {
        if ($this->hasBlocker()) {
            return PriceListImportRow::STATUS_BLOCKER;
        }

        return $this->issues === [] ? PriceListImportRow::STATUS_OK : PriceListImportRow::STATUS_NOTE;
    }

    /** Notes are surfaced in CATATAN so the reviewer sees them in the export. */
    public function catatanWithNotes(): ?string
    {
        $notes = [];

        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'note') {
                $notes[] = $issue['message'];
            }
        }

        $parts = array_filter([$this->catatan, ...$notes]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @return array<string, mixed> */
    public function toRowAttributes(): array
    {
        return [
            'sheet_name' => $this->sheetName,
            'source_row_number' => $this->sourceRowNumber,
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
            'issues' => $this->issues === [] ? null : $this->issues,
        ];
    }
}
