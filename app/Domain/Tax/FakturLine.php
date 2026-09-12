<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Money;
use App\Models\OrderLine;

/**
 * One item on a faktur.
 *
 * The figures are the line snapshots taken at `confirmed`, not anything
 * recomputed. `hargaTotal` is unit price times quantity *before* discount and
 * `diskon` is what came off, because that is how the faktur reports it — even
 * though our own line only stores the total after discount, so the gross has
 * to be rebuilt from the two.
 *
 * **Quantities are base units, not ordered units.** An order line records
 * both — 6 cartons *and* 72 pieces — and `unit_price_rupiah` is per base unit.
 * Pairing that price with the ordered quantity gives a gross a twelfth of the
 * real one on a carton line, which is a faktur whose own columns contradict
 * each other and whose DPP looks ten times too large beside them. The OF row
 * has no unit column, so reporting the base figures loses nothing and is the
 * only pair that multiplies out correctly.
 */
final class FakturLine
{
    private function __construct(
        public readonly string $kode,
        public readonly string $nama,
        public readonly int $hargaSatuanRupiah,
        public readonly int $jumlahBarang,
        public readonly int $hargaTotalRupiah,
        public readonly int $diskonRupiah,
        public readonly int $dppRupiah,
        public readonly int $ppnRupiah,
        /** The base unit the quantity is counted in — PCS or SET. */
        public readonly string $satuan = 'PCS',
    ) {}

    public static function fromOrderLine(OrderLine $line): self
    {
        /*
         * Unit price may carry four decimal places where fractional rupiah is
         * unavoidable; the faktur takes whole rupiah. Rounding it here changes
         * nothing that matters, because the DPP and PPN below are the stored
         * line figures rather than anything derived from this number — the
         * unit price on a faktur is descriptive, and the tax is not computed
         * from it.
         */
        $unitPrice = Money::roundToRupiah($line->unit_price_rupiah ?? 0);
        $qty = (int) $line->qty_base;
        $gross = $unitPrice * $qty;
        $net = (int) $line->line_total_rupiah;

        return new self(
            kode: (string) $line->sku,
            nama: self::describe($line),
            hargaSatuanRupiah: $unitPrice,
            jumlahBarang: $qty,
            hargaTotalRupiah: $gross,
            /*
             * Derived rather than read from discount_rupiah, so that gross less
             * discount always equals the net the customer was billed. Rounding
             * the unit price can move the gross by a rupiah or two on a long
             * line, and a faktur whose own arithmetic does not close is
             * rejected on upload.
             */
            diskonRupiah: max(0, $gross - $net),
            dppRupiah: (int) $line->dpp_rupiah,
            ppnRupiah: (int) $line->ppn_rupiah,
            // The unit was snapshotted with the quantity; the product is only
            // consulted for lines written before the snapshot column existed.
            satuan: strtoupper((string) ($line->satuan_dasar_snapshot ?: ($line->product?->satuan_dasar ?? 'PCS'))),
        );
    }

    /** Brand and description as printed, falling back to the code. */
    private static function describe(OrderLine $line): string
    {
        $text = trim(
            ($line->merk_snapshot ?? $line->product?->merk ?? '')
            .' '.($line->description_snapshot ?? $line->product?->description ?? '')
        );

        return $text !== '' ? $text : (string) $line->sku;
    }
}
