<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * One month of the business, on one sheet.
 *
 * Two kinds of number live here and the screen must say which is which:
 * the flow figures (penjualan, uang masuk) belong to the chosen month,
 * while the position figures (piutang, umur piutang) are how things stand
 * **today** — an ageing "as of the end of March" would need the ledger
 * replayed and would answer a question nobody at this desk is asking.
 *
 * @phpstan-type BarisTop array{dimensi: string, penjualan: int, margin: int|null}
 */
final class Ringkasan
{
    /**
     * @param  list<array{dimensi: string, penjualan: int, margin: int|null}>  $topPelanggan
     * @param  list<array{dimensi: string, penjualan: int, margin: int|null}>  $topMerk
     * @param  list<array{label: string, nilai: int}>  $umurPiutang
     * @param  list<string>  $catatan
     */
    public function __construct(
        public readonly Period $period,
        public readonly int $penjualan,
        public readonly int $faktur,
        public readonly ?int $hpp,
        public readonly ?int $margin,
        public readonly ?float $marginPersen,
        public readonly int $uangMasuk,
        public readonly int $piutang,
        public readonly array $umurPiutang,
        public readonly array $topPelanggan,
        public readonly array $topMerk,
        public readonly array $catatan,
    ) {}
}
