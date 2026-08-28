<?php

declare(strict_types=1);

namespace App\Domain\Insight;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountType;
use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind every dashboard chart, in one tested place.
 *
 * Charts are the easiest screen to quietly get wrong — a wrong figure in a
 * table gets challenged, a wrong bar just shapes a decision — so each query
 * lives here with a test on it rather than inline in a widget. All of them
 * derive from the same sources the rest of the system reads: orders that
 * were actually committed, the payment ledger's idea of outstanding, the
 * stock ledger, and — for money in and money out — the journal itself, so
 * the finance chart cannot disagree with the laba rugi.
 *
 * Everything is region-scoped by the same global scopes as the screens
 * around it; a marketing reading all regions sees all-region bars, the
 * same as their queues.
 */
class ChartStats
{
    /** Statuses that mean the customer actually committed to the goods. */
    private const COMMITTED = [
        OrderStatus::Confirmed,
        OrderStatus::AwaitingPayment,
        OrderStatus::Paid,
        OrderStatus::Shipped,
        OrderStatus::Completed,
    ];

    /**
     * One customer's monthly spend, oldest month first, zero-filled.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function monthlySpend(Company $company, int $months = 12): array
    {
        return $this->monthlyOrderTotals($months, fn ($q) => $q->where('orders.company_id', $company->id));
    }

    /**
     * Monthly committed sales for a seat: a sales' own orders, a marketing's
     * customers, everything for anyone else (the Owner).
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function monthlySales(?User $seat, int $months = 12): array
    {
        return $this->monthlyOrderTotals($months, function ($q) use ($seat) {
            match ($seat?->role()) {
                Role::Sales => $q->where('orders.sales_user_id', $seat->getKey()),
                Role::Marketing => $q->whereIn(
                    'orders.company_id',
                    Company::query()->select('id')->where('marketing_user_id', $seat->getKey()),
                ),
                default => null,
            };
        });
    }

    /**
     * Outstanding debt by age of the invoice, on the organisation's own
     * lines: inside the 30-day term, aging, near the freeze, frozen.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function debtAgeBuckets(Company $company): array
    {
        $labels = ['≤ 30 hari', '31–120 hari', '121–150 hari', '> 150 hari (terkunci)'];
        $values = [0, 0, 0, 0];

        Invoice::query()
            ->where('company_id', $company->id)
            ->where('status', Invoice::STATUS_OPEN)
            ->get()
            ->each(function (Invoice $invoice) use (&$values) {
                $umur = (int) Carbon::parse($invoice->issued_on)->diffInDays(today());
                $bucket = match (true) {
                    $umur <= 30 => 0,
                    $umur <= 120 => 1,
                    $umur <= 150 => 2,
                    default => 3,
                };
                $values[$bucket] += $invoice->amountOutstanding();
            });

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * The biggest outstanding balances, per customer — a marketing sees their
     * own customers, anyone else the whole region.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function outstandingByCustomer(?User $seat, int $limit = 10): array
    {
        $totals = Invoice::query()
            ->where('status', Invoice::STATUS_OPEN)
            ->when(
                $seat?->role() === Role::Marketing,
                fn ($q) => $q->whereHas('company', fn ($c) => $c->where('marketing_user_id', $seat->getKey())),
            )
            ->with('company')
            ->get()
            ->groupBy('company_id')
            ->map(fn ($invoices) => [
                'nama' => $invoices->first()->company->nama,
                'sisa' => (int) $invoices->sum(fn (Invoice $i) => $i->amountOutstanding()),
            ])
            ->filter(fn ($row) => $row['sisa'] > 0)
            ->sortByDesc('sisa')
            ->take($limit)
            ->values();

        return [
            'labels' => $totals->pluck('nama')->all(),
            'values' => $totals->pluck('sisa')->all(),
        ];
    }

    /**
     * Goods in and goods out per day, magnitudes both, for the last N days.
     *
     * @return array{labels: list<string>, masuk: list<int>, keluar: list<int>}
     */
    public function stockFlow(int $days = 30): array
    {
        $start = today()->subDays($days - 1);

        $rows = StockMovement::query()
            ->where('created_at', '>=', $start->startOfDay())
            ->groupBy(DB::raw('DATE(created_at)'))
            ->select(
                DB::raw('DATE(created_at) AS hari'),
                DB::raw('SUM(CASE WHEN qty_signed > 0 THEN qty_signed ELSE 0 END) AS masuk'),
                DB::raw('SUM(CASE WHEN qty_signed < 0 THEN -qty_signed ELSE 0 END) AS keluar'),
            )
            ->get()
            ->keyBy('hari');

        $labels = $masuk = $keluar = [];

        for ($d = 0; $d < $days; $d++) {
            $hari = $start->copy()->addDays($d);
            $key = $hari->toDateString();
            $labels[] = $hari->format('d/m');
            $masuk[] = (int) ($rows[$key]->masuk ?? 0);
            $keluar[] = (int) ($rows[$key]->keluar ?? 0);
        }

        return ['labels' => $labels, 'masuk' => $masuk, 'keluar' => $keluar];
    }

    /**
     * What the shelf is worth, by category, at moving-average cost — the
     * same figures the valuation and the neraca read.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function stockValueByCategory(): array
    {
        $rows = DB::table('product_costs')
            ->join('products', 'products.kode', '=', 'product_costs.sku')
            ->whereBoundRegion('product_costs')
            ->where('product_costs.qty_base', '>', 0)
            ->groupBy('products.kategori')
            ->select('products.kategori', DB::raw('SUM(product_costs.value_rupiah) AS nilai'))
            ->orderByDesc(DB::raw('SUM(product_costs.value_rupiah)'))
            ->get();

        return [
            'labels' => $rows->pluck('kategori')->all(),
            'values' => $rows->map(fn ($r) => (int) $r->nilai)->all(),
        ];
    }

    /**
     * Money in against money out, monthly, straight from the journal — so
     * this chart and the laba rugi can never tell different stories.
     *
     * @return array{labels: list<string>, pendapatan: list<int>, beban: list<int>}
     */
    public function revenueVsExpense(int $months = 12): array
    {
        $start = today()->startOfMonth()->subMonths($months - 1);

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereBoundRegion('journal_entries')
            ->where('journal_entries.tanggal', '>=', $start->toDateString())
            ->whereIn('accounts.tipe', [AccountType::Pendapatan->value, AccountType::Beban->value])
            ->groupBy(DB::raw("TO_CHAR(journal_entries.tanggal, 'YYYY-MM')"), 'accounts.tipe')
            ->select(
                DB::raw("TO_CHAR(journal_entries.tanggal, 'YYYY-MM') AS bulan"),
                'accounts.tipe',
                // Revenue is credit-normal, expense debit-normal; each sum is
                // signed its own way so reversals net out on both sides.
                DB::raw('SUM(CASE WHEN accounts.tipe = \''.AccountType::Pendapatan->value.'\' '
                    .'THEN journal_lines.kredit_rupiah - journal_lines.debit_rupiah '
                    .'ELSE journal_lines.debit_rupiah - journal_lines.kredit_rupiah END) AS jumlah'),
            )
            ->get();

        [$labels, $pendapatan, $beban] = [[], [], []];

        for ($m = 0; $m < $months; $m++) {
            $bulan = $start->copy()->addMonths($m);
            $key = $bulan->format('Y-m');
            $labels[] = $bulan->translatedFormat('M y');
            $pendapatan[] = (int) $rows->first(fn ($r) => $r->bulan === $key && $r->tipe === AccountType::Pendapatan->value)?->jumlah;
            $beban[] = (int) $rows->first(fn ($r) => $r->bulan === $key && $r->tipe === AccountType::Beban->value)?->jumlah;
        }

        return ['labels' => $labels, 'pendapatan' => $pendapatan, 'beban' => $beban];
    }

    /**
     * @param  callable(Builder): mixed  $filter
     * @return array{labels: list<string>, values: list<int>}
     */
    private function monthlyOrderTotals(int $months, callable $filter): array
    {
        $start = today()->startOfMonth()->subMonths($months - 1);

        $rows = Order::query()
            ->whereIn('orders.status', self::COMMITTED)
            ->where('orders.created_at', '>=', $start->startOfDay())
            ->tap($filter)
            ->groupBy(DB::raw("TO_CHAR(orders.created_at, 'YYYY-MM')"))
            ->select(
                DB::raw("TO_CHAR(orders.created_at, 'YYYY-MM') AS bulan"),
                DB::raw('SUM(orders.total_rupiah) AS jumlah'),
            )
            ->pluck('jumlah', 'bulan');

        [$labels, $values] = [[], []];

        for ($m = 0; $m < $months; $m++) {
            $bulan = $start->copy()->addMonths($m);
            $labels[] = $bulan->translatedFormat('M y');
            $values[] = (int) ($rows[$bulan->format('Y-m')] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
