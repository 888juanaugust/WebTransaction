<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

/**
 * The five-bucket diff preview, plus the safety brake verdict.
 *
 * Nothing is published until a human has looked at this.
 */
final readonly class ImportDiff
{
    /**
     * @param  list<array{kode: string, harga_lama: int, harga_baru: int, delta_bps: int}>  $biggestMoves
     * @param  list<string>  $brakeReasons
     */
    public function __construct(
        public int $newSkus,
        public int $priceChanged,
        public int $unchanged,
        public int $missingFromFile,
        public int $errors,
        public array $biggestMoves,
        public bool $brakeTripped,
        public array $brakeReasons,
        public bool $isFullReplacement,
    ) {}

    public function totalInFile(): int
    {
        return $this->newSkus + $this->priceChanged + $this->unchanged + $this->errors;
    }

    /** Share of priced SKUs whose price moved, in basis points. */
    public function changedShareBps(): int
    {
        $comparable = $this->priceChanged + $this->unchanged;

        if ($comparable === 0) {
            return 0;
        }

        return (int) round($this->priceChanged * 10_000 / $comparable);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'buckets' => [
                'sku_baru' => $this->newSkus,
                'harga_berubah' => $this->priceChanged,
                'tidak_berubah' => $this->unchanged,
                'tidak_ada_di_file' => $this->missingFromFile,
                'error' => $this->errors,
            ],
            'changed_share_bps' => $this->changedShareBps(),
            'biggest_moves' => $this->biggestMoves,
            'brake_tripped' => $this->brakeTripped,
            'brake_reasons' => $this->brakeReasons,
            'is_full_replacement' => $this->isFullReplacement,
        ];
    }

    /**
     * The confirmation text the second approval must name back.
     *
     * "Are you sure?" is useless; a number the approver has to read is not.
     */
    public function brakeSummary(): ?string
    {
        if (! $this->brakeTripped) {
            return null;
        }

        return implode(' ', $this->brakeReasons);
    }
}
