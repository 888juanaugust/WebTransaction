<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customers who have stopped ordering.
 *
 * CLAUDE.md says it plainly: B2B buyers reorder the same fifteen SKUs forever.
 * They restock; they do not shop. That makes a **broken rhythm** the clearest
 * signal this business has, and the one nothing else in the system watches
 * for. Nobody notices a bengkel that ordered every three weeks and has not
 * ordered for two months — there is no alert, no empty queue, no complaint.
 * The revenue just stops, and it stops quietly.
 *
 * **Measured against each customer's own rhythm, not one number for
 * everybody.** A distributor ordering weekly and a workshop ordering
 * quarterly are both healthy; a single "60 days" threshold calls the first one
 * fine when it is three weeks late and the second one lapsed when it is early.
 * So the gap that matters is measured in multiples of that customer's own
 * median interval between orders.
 *
 * Ranked by what they were worth, because the point is to decide who to ring
 * first, and a customer who spent five million a month leaving is a different
 * problem from one who spent fifty thousand.
 */
class LapsedCustomers
{
    /**
     * How many of a customer's own ordering intervals must pass before they
     * count as lapsed.
     *
     * Two rather than one, because ordering a little late is normal and a
     * report that flags everybody flags nobody.
     */
    private const OVERDUE_FACTOR = 2.0;

    /**
     * Below this many orders there is no rhythm to break.
     *
     * A customer who has ordered twice has one interval, and one interval is
     * an anecdote. Their absence might be churn or might be how they always
     * bought — and telling somebody to ring a customer who was never regular
     * wastes the credibility the report needs.
     */
    private const MIN_ORDERS = 3;

    /** Fallback when a customer is regular but their history is short. */
    private const DEFAULT_INTERVAL_DAYS = 45;

    public function build(?Carbon $asOf = null): ReportTable
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();

        $rows = [];

        foreach ($this->orderHistory() as $companyId => $history) {
            if (count($history['dates']) < self::MIN_ORDERS) {
                continue;
            }

            $interval = $this->medianInterval($history['dates']) ?? self::DEFAULT_INTERVAL_DAYS;
            $last = Carbon::parse(end($history['dates']));
            $silent = (int) $last->diffInDays($asOf);

            if ($silent < $interval * self::OVERDUE_FACTOR) {
                continue;
            }

            $months = max(1, (int) round(
                Carbon::parse($history['dates'][0])->diffInDays($last) / 30,
            ));

            $rows[] = [
                'dimensi' => $history['nama'],
                'order' => count($history['dates']),
                'terakhir' => $last->toDateString(),
                'diam' => $silent,
                'biasanya' => $interval,
                // What they were worth per month while they were still
                // ordering — the figure that says who to ring first.
                'per_bulan' => (int) round($history['nilai'] / $months),
                'seumur_hidup' => $history['nilai'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['per_bulan'] <=> $a['per_bulan']);

        return new ReportTable(
            judul: 'Pelanggan yang berhenti pesan',
            period: Period::asOf($asOf),
            columns: [
                ReportColumn::text('dimensi', 'Pelanggan'),
                ReportColumn::number('order', 'Order'),
                ReportColumn::date('terakhir', 'Terakhir pesan'),
                ReportColumn::number('diam', 'Diam (hari)'),
                ReportColumn::number('biasanya', 'Biasanya tiap (hari)'),
                ReportColumn::money('per_bulan', 'Nilai per bulan'),
                ReportColumn::money('seumur_hidup', 'Total pernah belanja'),
            ],
            rows: $rows,
            totals: [
                'dimensi' => 'TOTAL',
                'order' => array_sum(array_column($rows, 'order')),
                'per_bulan' => array_sum(array_column($rows, 'per_bulan')),
                'seumur_hidup' => array_sum(array_column($rows, 'seumur_hidup')),
            ],
            catatan: [
                sprintf(
                    'Yang masuk daftar: pelanggan dengan minimal %d order yang sudah diam '
                    .'lebih dari %g kali jarak pesan mereka sendiri. Pelanggan yang memang '
                    .'jarang pesan tidak ikut, karena jaraknya dihitung dari kebiasaan mereka.',
                    self::MIN_ORDERS,
                    self::OVERDUE_FACTOR,
                ),
                'Nilai per bulan adalah rata-rata selama mereka masih aktif — itu yang '
                .'hilang tiap bulan selama mereka tidak kembali.',
            ],
        );
    }

    /**
     * Every active customer's order dates and lifetime value.
     *
     * Only orders that got past `confirmed`. A draft or a rejected order is
     * not evidence anybody bought anything, and counting them would invent a
     * rhythm out of enquiries.
     *
     * @return array<int, array{nama: string, dates: list<string>, nilai: int}>
     */
    private function orderHistory(): array
    {
        $rows = DB::table('orders')
            ->whereBoundRegion('orders')
            ->join('companies', 'orders.company_id', '=', 'companies.id')
            ->where('companies.status', Company::STATUS_ACTIVE)
            ->whereNotNull('orders.confirmed_at')
            ->whereNotIn('orders.status', [
                OrderStatus::Rejected->value,
                OrderStatus::Expired->value,
            ])
            ->orderBy('orders.company_id')
            ->orderBy('orders.confirmed_at')
            ->get([
                'orders.company_id',
                'orders.confirmed_at',
                'orders.total_rupiah',
                'companies.nama',
            ]);

        $history = [];

        foreach ($rows as $row) {
            $id = (int) $row->company_id;

            $history[$id] ??= ['nama' => (string) $row->nama, 'dates' => [], 'nilai' => 0];
            $history[$id]['dates'][] = (string) $row->confirmed_at;
            $history[$id]['nilai'] += (int) $row->total_rupiah;
        }

        return $history;
    }

    /**
     * The middle gap between consecutive orders, in days.
     *
     * Median rather than mean, because one long gap — a holiday, a dispute, a
     * factory shutdown — drags an average out far enough to hide a customer
     * who has genuinely stopped. The middle value ignores it.
     *
     * @param  list<string>  $dates  ordered ascending
     */
    private function medianInterval(array $dates): ?int
    {
        $gaps = [];

        for ($i = 1; $i < count($dates); $i++) {
            $gaps[] = (int) Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
        }

        if ($gaps === []) {
            return null;
        }

        sort($gaps);

        $middle = intdiv(count($gaps), 2);

        $median = count($gaps) % 2 === 1
            ? $gaps[$middle]
            : (int) round(($gaps[$middle - 1] + $gaps[$middle]) / 2);

        // A customer who ordered twice in one day would otherwise have an
        // interval of zero, and every day after that would be "overdue".
        return max(1, $median);
    }
}
