<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Giro\GiroDirection;
use App\Domain\Giro\GiroStatus;
use App\Domain\Money;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who owes us what, and how long they have owed it.
 *
 * The dashboard already lists overdue invoices; this answers a different
 * question. That queue is a list of documents to chase today. This is a list
 * of **customers**, with their debt graded by age, which is what somebody uses
 * to decide who gets a phone call and who gets put on stop.
 *
 * **The total ties to Piutang Usaha, by construction.** Per-invoice balances
 * are invoiced less paid less credited, which is the same rule
 * `OutstandingReceivables` applies in aggregate — and this class checks itself
 * against that rule rather than trusting the arithmetic to have stayed in
 * step. An ageing report that does not add up to the balance sheet is one
 * finance will stop believing at exactly the moment it matters.
 *
 * Unmatched payments are the reason that check is not free. Money in the bank
 * that nobody has attached to an invoice reduces what a customer owes but
 * belongs to no bucket, so it is shown as its own line rather than spread
 * across buckets it may not belong to.
 */
class ReceivablesAgeing
{
    /** Bucket edges, in days past due. */
    private const BUCKETS = [30, 60, 90];

    public function __construct(
        private readonly OutstandingReceivables $receivables,
    ) {}

    public function build(?Carbon $asOf = null): ReportTable
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();

        $rows = [];

        foreach ($this->openInvoices() as $invoice) {
            $companyId = (int) $invoice->company_id;

            $rows[$companyId] ??= [
                'dimensi' => (string) $invoice->nama,
                'belum_jatuh_tempo' => 0,
                'b1' => 0,
                'b2' => 0,
                'b3' => 0,
                'b4' => 0,
                'belum_dicocokkan' => 0,
                'dijamin_giro' => 0,
                'total' => 0,
                'tertua' => null,
            ];

            $outstanding = (int) $invoice->total_rupiah
                - (int) $invoice->dibayar
                - (int) $invoice->dikredit;

            if ($outstanding === 0) {
                continue;
            }

            $due = Carbon::parse($invoice->due_date)->endOfDay();

            /*
             * Clamped at zero rather than left signed. Carbon returns a
             * negative difference for a date still in the future, which
             * `bucketFor()` would also read as not-late — so this is belt and
             * braces, and no test can tell the two apart. It stays because the
             * clamp does not depend on which way round Carbon subtracts, and
             * that default has changed between major versions before.
             */
            $daysLate = $due->greaterThanOrEqualTo($asOf) ? 0 : (int) $due->diffInDays($asOf);

            $rows[$companyId][$this->bucketFor($daysLate)] += $outstanding;
            $rows[$companyId]['total'] += $outstanding;

            if ($daysLate > 0
                && ($rows[$companyId]['tertua'] === null || $daysLate > $rows[$companyId]['tertua'])) {
                $rows[$companyId]['tertua'] = $daysLate;
            }
        }

        // Payments nobody has attached to an invoice. They reduce the debt and
        // belong to no bucket, so they sit in their own column rather than
        // being guessed into one.
        foreach ($this->unmatchedPayments() as $companyId => $payment) {
            $rows[$companyId] ??= [
                'dimensi' => $payment['nama'],
                'belum_jatuh_tempo' => 0, 'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0,
                'belum_dicocokkan' => 0, 'dijamin_giro' => 0, 'total' => 0, 'tertua' => null,
            ];

            $rows[$companyId]['belum_dicocokkan'] -= $payment['nilai'];
            $rows[$companyId]['total'] -= $payment['nilai'];
        }

        /*
         * Giro in hand, for the same reason unmatched payments get their own
         * column: it reduces what Piutang Usaha carries and belongs to no
         * bucket. Bucketing it would be wrong twice over — the buckets grade
         * *invoices* by age, and a giro has its own due date that has nothing
         * to do with the invoice's.
         *
         * The customer still owes this money. What has changed is that they
         * have signed something dated, which is why it comes off the ledger
         * balance and not off their credit limit.
         */
        foreach ($this->giroHeld() as $companyId => $giro) {
            $rows[$companyId] ??= [
                'dimensi' => $giro['nama'],
                'belum_jatuh_tempo' => 0, 'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0,
                'belum_dicocokkan' => 0, 'dijamin_giro' => 0, 'total' => 0, 'tertua' => null,
            ];

            $rows[$companyId]['dijamin_giro'] -= $giro['nilai'];
            $rows[$companyId]['total'] -= $giro['nilai'];
        }

        $rows = array_values(array_filter($rows, fn (array $row) => $row['total'] !== 0));

        // Biggest debt first: that is the order somebody works down.
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return new ReportTable(
            judul: 'Umur piutang',
            period: Period::asOf($asOf),
            columns: $this->columns(),
            rows: $rows,
            totals: $this->totals($rows),
            catatan: $this->caveats($rows),
        );
    }

    /**
     * Invoices with their payments and credits already netted, in one query.
     *
     * Sub-selects rather than a walk over the models: an ageing report over a
     * year of invoices would otherwise run two queries per row, and the report
     * that gets slow is the report nobody opens.
     */
    private function openInvoices()
    {
        $paid = DB::table('payment_entries')
            ->selectRaw('COALESCE(SUM(amount_rupiah), 0)')
            ->whereColumn('payment_entries.invoice_id', 'invoices.id');

        $credited = DB::table('credit_notes')
            ->selectRaw('COALESCE(SUM(total_rupiah), 0)')
            ->whereColumn('credit_notes.invoice_id', 'invoices.id')
            /*
             * Posted only, and redundantly so: a draft carries no figures at
             * all until the poster computes them, so an unposted note sums to
             * nothing and could not move a balance even without this. Kept
             * because the query should say what it means.
             */
            ->where('credit_notes.status', CreditNote::STATUS_POSTED);

        return DB::table('invoices')
            ->join('companies', 'invoices.company_id', '=', 'companies.id')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->select([
                'invoices.id', 'invoices.company_id', 'invoices.due_date',
                'invoices.total_rupiah', 'companies.nama',
            ])
            ->selectSub($paid, 'dibayar')
            ->selectSub($credited, 'dikredit')
            ->orderBy('invoices.due_date')
            ->get();
    }

    /**
     * Face value of outstanding customer giro, per customer.
     *
     * @return array<int, array{nama: string, nilai: int}>
     */
    private function giroHeld(): array
    {
        return DB::table('giros')
            ->join('companies', 'giros.company_id', '=', 'companies.id')
            ->where('giros.arah', GiroDirection::Masuk->value)
            ->where('giros.status', GiroStatus::Beredar->value)
            ->groupBy('giros.company_id', 'companies.nama')
            ->selectRaw('giros.company_id AS id, MIN(companies.nama) AS nama, SUM(nilai_rupiah) AS nilai')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => [
                'nama' => (string) $row->nama,
                'nilai' => (int) $row->nilai,
            ]])
            ->all();
    }

    /** @return array<int, array{nama: string, nilai: int}> */
    private function unmatchedPayments(): array
    {
        return DB::table('payment_entries')
            ->join('companies', 'payment_entries.company_id', '=', 'companies.id')
            ->whereNull('payment_entries.invoice_id')
            ->groupBy('payment_entries.company_id', 'companies.nama')
            ->selectRaw('payment_entries.company_id AS id, MIN(companies.nama) AS nama, SUM(amount_rupiah) AS nilai')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => [
                'nama' => (string) $row->nama,
                'nilai' => (int) $row->nilai,
            ]])
            ->all();
    }

    private function bucketFor(int $daysLate): string
    {
        return match (true) {
            $daysLate <= 0 => 'belum_jatuh_tempo',
            $daysLate <= self::BUCKETS[0] => 'b1',
            $daysLate <= self::BUCKETS[1] => 'b2',
            $daysLate <= self::BUCKETS[2] => 'b3',
            default => 'b4',
        };
    }

    /** @return list<ReportColumn> */
    private function columns(): array
    {
        return [
            ReportColumn::text('dimensi', 'Pelanggan'),
            ReportColumn::money('belum_jatuh_tempo', 'Belum jatuh tempo'),
            ReportColumn::money('b1', '1–30 hari'),
            ReportColumn::money('b2', '31–60 hari'),
            ReportColumn::money('b3', '61–90 hari'),
            ReportColumn::money('b4', '> 90 hari'),
            ReportColumn::money('belum_dicocokkan', 'Belum dicocokkan'),
            /*
             * Hidden entirely when nobody is holding a giro. Eight money
             * columns is the most that fits, and a business that settles by
             * transfer would carry a column of zeroes to the right edge
             * forever. It appears the day the first cheque comes in, which is
             * the day it starts meaning something.
             */
            ReportColumn::money('dijamin_giro', 'Dijamin giro',
                fn () => $this->receivables->giroHeld(null) !== 0),
            ReportColumn::money('total', 'Total'),
            /*
             * There is deliberately no "oldest debt in days" column. It reads
             * well and says nothing the buckets do not — a figure in the
             * over-90 column already means over 90 days — and carrying it made
             * nine money columns, which pushed the total off the right edge of
             * a 1400px screen. The buckets are the report; that was decoration.
             */
        ];
    }

    private function totals(array $rows): array
    {
        $totals = ['dimensi' => 'TOTAL', 'tertua' => null];

        foreach ([
            'belum_jatuh_tempo', 'b1', 'b2', 'b3', 'b4',
            'belum_dicocokkan', 'dijamin_giro', 'total',
        ] as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        return $totals;
    }

    /**
     * Prove the report against the balance sheet, and say so either way.
     *
     * @return list<string>
     */
    private function caveats(array $rows): array
    {
        $reported = array_sum(array_column($rows, 'total'));
        $control = $this->receivables->total();

        if ($reported === $control) {
            return [];
        }

        return [sprintf(
            'PERIKSA: total laporan ini %s tidak sama dengan Piutang Usaha di buku besar %s. '
            .'Selisih %s. Salah satunya salah — jangan dipakai sebelum dicari sebabnya.',
            Money::format($reported),
            Money::format($control),
            Money::format($reported - $control),
        )];
    }
}
