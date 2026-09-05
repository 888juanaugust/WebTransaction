<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Tax\FilingScope;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PurchaseReturn;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;

/**
 * The masa pajak in one line: PPN Keluaran − PPN Masukan = what is owed.
 *
 * Both sides already exist in the documents; this report only nets them the
 * way the accountant files them. Keluaran is the month's issued invoices
 * less posted credit notes — the same figures the Coretax export carries.
 * Masukan is supplier bills dated in the month (by the supplier's faktur
 * date, which is what the credit follows) less posted purchase returns and
 * supplier credit notes, whose PPN went back with the goods or the price.
 *
 * Read-only and derived, like every report here. The caveat is printed
 * rather than assumed: this recap follows the documents in this system, and
 * a masukan faktur that never got entered as a bill is invisible to it —
 * the filing figure is the accountant's call, made with this sheet in hand.
 *
 * **Entity-wide, not region-scoped**, unlike the management reports beside it.
 * This one is filed: one PT, one NPWP, one SPT Masa a month, and it has to
 * carry every document the company raised whichever region's books took it.
 * See `FilingScope`. Scoped, it agreed with a region-scoped Coretax export
 * about the same wrong number — which is worse than disagreeing, because
 * these two figures are exactly what an accountant cross-checks.
 */
class RekapPpn
{
    public function build(Period $period): ReportTable
    {
        $keluaranFaktur = (int) FilingScope::entityWide(Invoice::class)
            ->whereIn('status', [Invoice::STATUS_OPEN, Invoice::STATUS_PAID])
            ->whereBetween('issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum('ppn_rupiah');

        $keluaranNotaKredit = (int) FilingScope::entityWide(CreditNote::class)
            ->where('status', CreditNote::STATUS_POSTED)
            ->whereBetween('tanggal', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum('ppn_rupiah');

        $masukanTagihan = (int) FilingScope::entityWide(SupplierBill::class)
            ->whereIn('status', [SupplierBill::STATUS_OPEN, SupplierBill::STATUS_PAID])
            ->whereBetween('tanggal_faktur', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum('ppn_rupiah');

        $masukanRetur = (int) FilingScope::entityWide(PurchaseReturn::class)
            ->where('status', PurchaseReturn::STATUS_POSTED)
            ->whereBetween('tanggal', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum('ppn_rupiah');

        $masukanNotaKredit = (int) FilingScope::entityWide(SupplierCreditNote::class)
            ->where('status', SupplierCreditNote::STATUS_POSTED)
            ->whereBetween('tanggal', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum('ppn_rupiah');

        $keluaran = $keluaranFaktur - $keluaranNotaKredit;
        $masukan = $masukanTagihan - $masukanRetur - $masukanNotaKredit;
        $bersih = $keluaran - $masukan;

        $rows = [
            ['pos' => 'PPN Keluaran — faktur terbit', 'jumlah' => $keluaranFaktur],
            ['pos' => '− Nota kredit (posted)', 'jumlah' => -$keluaranNotaKredit],
            ['pos' => 'PPN Keluaran bersih', 'jumlah' => $keluaran],
            ['pos' => 'PPN Masukan — tagihan pemasok', 'jumlah' => $masukanTagihan],
            ['pos' => '− Retur pembelian (posted)', 'jumlah' => -$masukanRetur],
            ['pos' => '− Nota kredit pemasok (posted)', 'jumlah' => -$masukanNotaKredit],
            ['pos' => 'PPN Masukan bersih', 'jumlah' => $masukan],
        ];

        return new ReportTable(
            judul: 'Rekap PPN masa',
            period: $period,
            columns: [
                ReportColumn::text('pos', 'Pos'),
                ReportColumn::money('jumlah', 'Jumlah'),
            ],
            rows: $rows,
            totals: [
                'pos' => $bersih >= 0 ? 'KURANG BAYAR (disetor)' : 'LEBIH BAYAR (dikompensasi)',
                'jumlah' => $bersih,
            ],
            catatan: [
                'Rekap ini mengikuti dokumen di sistem: faktur masukan yang belum pernah '
                .'dientri sebagai tagihan pemasok tidak terbaca di sini. Angka pelaporan '
                .'final tetap keputusan akuntan.',
                'Masukan dihitung dari tanggal faktur pemasok — tanggal yang menentukan '
                .'masa pengkreditannya.',
            ],
        );
    }
}
