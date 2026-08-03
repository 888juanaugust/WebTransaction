<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * The tax figures for one line, or for a total assembled from lines.
 *
 * `hargaJual` is the selling price after discount — the figure PPN is derived
 * from. `dpp` is the Dasar Pengenaan Pajak actually reported to Coretax.
 */
final readonly class TaxBreakdown
{
    public function __construct(
        public int $hargaJual,
        public int $dpp,
        public int $ppn,
        public string $kodeTransaksi,
    ) {}

    /** What the customer owes for this line: selling price plus PPN. */
    public function total(): int
    {
        return $this->hargaJual + $this->ppn;
    }

    /**
     * Effective PPN burden in basis points, against harga jual.
     *
     * For ordinary goods under PMK 131/2024 this lands on ~1100 (11%) even
     * though the headline rate is 12% — that is the whole point of the 11/12
     * DPP factor, and a useful thing to assert in tests.
     */
    public function effectiveRateBps(): int
    {
        if ($this->hargaJual === 0) {
            return 0;
        }

        return (int) round($this->ppn * 10_000 / $this->hargaJual);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'harga_jual' => $this->hargaJual,
            'dpp' => $this->dpp,
            'ppn' => $this->ppn,
            'total' => $this->total(),
            'kode_transaksi' => $this->kodeTransaksi,
        ];
    }
}
