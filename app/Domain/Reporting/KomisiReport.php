<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Access\Role;
use App\Models\CommissionRate;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the sellers earned on the month's *collected* sales — and how the
 * sales seats stand against their targets.
 *
 * Commission here is paid on settlement, not on invoicing. The operation
 * sells on credit: an invoice is a promise, and paying commission on
 * promises pays people for debt that may never arrive. So the base month is
 * the month the invoice finished settling — the date of its last payment
 * entry — and an invoice reopened by a reversal simply drops back out,
 * which is the claw-back, visible and automatic.
 *
 * Nothing is stored. Following the same rule as debt aging — derived
 * arithmetic, never stored state — komisi is recomputed from the ledgers on
 * every call. What cannot be re-derived is stored instead: the rate a person
 * was on (`commission_rates`, effective-dated, append-only) and the target
 * they were given (`sales_targets`). The rate applied is the one effective
 * on the settlement date, so a raise never rewrites an old month.
 *
 * The base is money the business actually keeps: total minus PPN — tax
 * collected for the state is nobody's sale — minus posted credit notes
 * against the invoice, net of their own PPN. An invoice settled entirely by
 * credit note has no payment entry, no settlement date, and correctly earns
 * nothing.
 *
 * Both seats of the customer's team earn: sales at their rate, marketing at
 * theirs, each on the same base. Seats are read from the company row as it
 * stands today — reassigning a customer moves future commission with them,
 * and the report says so in a note rather than pretending otherwise.
 */
class KomisiReport
{
    public function build(Period $period): ReportTable
    {
        $settled = $this->settledInvoices($period);

        $rows = [];

        foreach ($settled->groupBy(fn (array $s) => $s['user_id'].'|'.$s['peran']) as $group) {
            $first = $group->first();
            $user = $first['user'];

            $basis = $group->sum('basis');
            $komisi = $group->sum('komisi');

            $rows[] = [
                'nama' => $user->name,
                'peran' => Role::from($first['peran'])->label(),
                'peran_raw' => $first['peran'],
                'user_id' => $user->id,
                'faktur' => $group->count(),
                'basis' => $basis,
                'tarif' => null, // per-invoice; shown per line only when uniform
                'komisi' => $komisi,
                'target' => null,
                'pencapaian' => null,
            ];
        }

        // Targets attach to the sales seat: selling is what was targeted.
        $targets = SalesTarget::query()
            ->where('tahun', $period->from->year)
            ->where('bulan', $period->from->month)
            ->get()
            ->keyBy('user_id');

        foreach ($rows as &$row) {
            if ($row['peran_raw'] !== Role::Sales->value) {
                continue;
            }

            $target = $targets->get($row['user_id']);

            if ($target !== null) {
                $row['target'] = (int) $target->target_rupiah;
                $row['pencapaian'] = $target->target_rupiah > 0
                    ? round($row['basis'] / $target->target_rupiah * 100, 1)
                    : null;
            }
        }
        unset($row);

        // A sales seat with a target but no settled sales still belongs on
        // the sheet — zero against a target is the row that starts the
        // conversation.
        foreach ($targets as $userId => $target) {
            $seen = collect($rows)->contains(fn (array $r) => $r['user_id'] === (int) $userId
                && $r['peran_raw'] === Role::Sales->value);

            if (! $seen && $target->user !== null) {
                $rows[] = [
                    'nama' => $target->user->name,
                    'peran' => Role::Sales->label(),
                    'peran_raw' => Role::Sales->value,
                    'user_id' => (int) $userId,
                    'faktur' => 0,
                    'basis' => 0,
                    'tarif' => null,
                    'komisi' => 0,
                    'target' => (int) $target->target_rupiah,
                    'pencapaian' => 0.0,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['peran'], -$a['basis']] <=> [$b['peran'], -$b['basis']]);

        return new ReportTable(
            judul: 'Komisi & target',
            period: $period,
            columns: [
                ReportColumn::text('nama', 'Nama'),
                ReportColumn::text('peran', 'Peran'),
                ReportColumn::number('faktur', 'Faktur lunas'),
                ReportColumn::money('basis', 'Dasar (tanpa PPN)'),
                ReportColumn::money('komisi', 'Komisi'),
                ReportColumn::money('target', 'Target'),
                ReportColumn::percent('pencapaian', 'Capai'),
            ],
            rows: $rows,
            totals: [
                'nama' => 'TOTAL',
                'faktur' => array_sum(array_column($rows, 'faktur')),
                'basis' => array_sum(array_column($rows, 'basis')),
                'komisi' => array_sum(array_column($rows, 'komisi')),
            ],
            catatan: [
                'Komisi dihitung saat faktur LUNAS, bukan saat terbit — tagihan yang belum '
                .'dibayar belum menghasilkan komisi, dan faktur yang dibuka kembali oleh '
                .'pembalikan otomatis keluar dari sini.',
                'Dasar = total faktur tanpa PPN, dikurangi nota kredit atas faktur itu. '
                .'Tarif yang dipakai adalah tarif yang berlaku pada tanggal pelunasan.',
                'Kursi sales/marketing dibaca dari data pelanggan saat ini — memindahkan '
                .'pelanggan memindahkan komisi berikutnya.',
            ],
        );
    }

    /**
     * Every invoice whose settlement finished inside the period, expanded to
     * one entry per team seat with the commission already computed.
     *
     * @return Collection<int, array{user_id: int, peran: string, user: User, basis: int, komisi: int}>
     */
    private function settledInvoices(Period $period): Collection
    {
        $invoices = Invoice::query()
            ->whereIn('status', [Invoice::STATUS_PAID])
            ->with(['company.salesRep', 'company.marketingRep'])
            // The settlement moment is the last payment entry on the invoice.
            ->whereRaw('(select max(paid_at) from payment_entries where payment_entries.invoice_id = invoices.id) between ? and ?', [
                $period->from, $period->to,
            ])
            ->get();

        if ($invoices->isEmpty()) {
            return collect();
        }

        $credits = CreditNote::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->where('status', CreditNote::STATUS_POSTED)
            ->get()
            ->groupBy('invoice_id');

        $rates = CommissionRate::query()
            ->orderBy('berlaku_mulai')
            ->get()
            ->groupBy('user_id');

        $out = collect();

        foreach ($invoices as $invoice) {
            /*
             * When the last money reached this faktur, read through the
             * allocations. Asking the entries directly would miss every
             * invoice settled as part of a transfer covering several — those
             * entries name no single faktur — and the seller would lose the
             * commission on exactly the payments that arrive in a lump at
             * month end.
             */
            $settledAt = $invoice->allocations()
                ->join('payment_entries', 'payment_entries.id', '=', 'payment_allocations.payment_entry_id')
                ->max('payment_entries.paid_at');

            if ($settledAt === null) {
                continue; // settled without money — a full credit note earns nothing
            }

            $basis = (int) $invoice->total_rupiah - (int) $invoice->ppn_rupiah;

            foreach ($credits->get($invoice->id, collect()) as $credit) {
                $basis -= (int) $credit->total_rupiah - (int) $credit->ppn_rupiah;
            }

            if ($basis <= 0) {
                continue;
            }

            $company = $invoice->company;

            foreach ([
                [Role::Sales->value, $company?->salesRep],
                [Role::Marketing->value, $company?->marketingRep],
            ] as [$peran, $seat]) {
                if ($seat === null) {
                    continue;
                }

                $bp = $this->rateFor($rates->get($seat->id, collect()), Carbon::parse($settledAt));

                if ($bp === 0) {
                    continue;
                }

                $out->push([
                    'user_id' => (int) $seat->id,
                    'peran' => $peran,
                    'user' => $seat,
                    'basis' => $basis,
                    // Basis points on whole rupiah, rounded per invoice.
                    'komisi' => (int) round($basis * $bp / 10_000),
                ]);
            }
        }

        return $out;
    }

    /** The rate effective on a date: the latest row at or before it. */
    private function rateFor(Collection $userRates, Carbon $onDate): int
    {
        $effective = 0;

        foreach ($userRates as $rate) {
            if ($rate->berlaku_mulai->lessThanOrEqualTo($onDate)) {
                $effective = (int) $rate->basis_poin;
            }
        }

        return $effective;
    }
}
