<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Billing\OutstandingReceivables;
use App\Models\PaymentEntry;

/**
 * The owner's month-end read, composed — not queried.
 *
 * Every figure here already exists in a tested report: sales and margin come
 * from SalesReport (the one that reconciles to the profit and loss), the
 * ageing bands from ReceivablesAgeing (the one that ties to Piutang Usaha),
 * the receivable balance from OutstandingReceivables. This class only
 * arranges them on one sheet. The alternative — its own SUM over invoices —
 * would eventually disagree with the reports it summarises, and a summary
 * that contradicts its own detail pages is worse than no summary.
 *
 * The single number computed here is *uang masuk*: the payment ledger summed
 * over the month. Reversal entries are stored negative, so the plain sum is
 * already net of corrections. Deposit applications are excluded — applying a
 * deposit moves no money on the day; the cash was banked when it was taken.
 *
 * Region-scoped like every report: the bound region's books, or everything
 * when nothing is bound.
 */
class RingkasanBulanan
{
    private const TOP = 5;

    public function __construct(
        private readonly SalesReport $sales,
        private readonly ReceivablesAgeing $ageing,
        private readonly OutstandingReceivables $receivables,
    ) {}

    public function build(Period $period, bool $withCost = true): Ringkasan
    {
        $perPelanggan = $this->sales->build($period, SalesDimension::Pelanggan, $withCost);
        $perMerk = $this->sales->build($period, SalesDimension::Merk, $withCost);

        $ageingTable = $this->ageing->build();

        return new Ringkasan(
            period: $period,
            penjualan: (int) ($perPelanggan->totals['penjualan'] ?? 0),
            faktur: (int) ($perPelanggan->totals['faktur'] ?? 0),
            hpp: $withCost ? (int) ($perPelanggan->totals['hpp'] ?? 0) : null,
            margin: $withCost ? (int) ($perPelanggan->totals['margin'] ?? 0) : null,
            marginPersen: $withCost ? $perPelanggan->totals['margin_persen'] : null,
            uangMasuk: $this->uangMasuk($period),
            piutang: $this->receivables->total(),
            /*
             * Signed bucket totals, not the chart's clamped ones: on this
             * sheet the buckets sit beside the receivable balance, and the
             * reader must be able to add one to the other. Unmatched
             * payments and giro in hand show negative because that is how
             * they carry.
             */
            umurPiutang: $this->ageing->bucketTotals($ageingTable),
            topPelanggan: $this->top($perPelanggan),
            topMerk: $this->top($perMerk),
            catatan: $perPelanggan->catatan,
        );
    }

    private function uangMasuk(Period $period): int
    {
        return (int) PaymentEntry::query()
            ->whereIn('kind', [PaymentEntry::KIND_PAYMENT, PaymentEntry::KIND_REVERSAL])
            ->whereBetween('paid_at', [$period->from, $period->to])
            ->sum('amount_rupiah');
    }

    /** @return list<array{dimensi: string, penjualan: int, margin: int|null}> */
    private function top(ReportTable $table): array
    {
        return array_map(fn (array $row) => [
            'dimensi' => (string) $row['dimensi'],
            'penjualan' => (int) $row['penjualan'],
            'margin' => $row['margin'] !== null ? (int) $row['margin'] : null,
        ], array_slice($table->rows, 0, self::TOP));
    }
}
