<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\BalanceSheet;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\ProfitAndLoss;
use App\Domain\Accounting\StatementSection;
use App\Models\User;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Neraca and laba rugi.
 *
 * Two statements over the same journal, asking different questions of it. The
 * income statement asks what happened between two dates; the balance sheet
 * asks what is true on one. Most of the ways these go wrong are the same
 * mistake — reading a movement as a balance or the other way round — so the
 * dates get more attention here than the arithmetic does.
 */
class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(Ledger::class);
        $this->finance = User::factory()->role(Role::Finance)->create();
    }

    // ------------------------------------------------------------ laba rugi

    public function test_gross_profit_is_sales_less_what_the_goods_cost(): void
    {
        $this->trade(sale: 10_000_000, cost: 6_000_000);

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(10_000_000, $pl->totalPendapatan());
        $this->assertSame(6_000_000, $pl->totalHargaPokok());
        $this->assertSame(4_000_000, $pl->labaKotor());
    }

    public function test_operating_expense_sits_below_gross_profit_not_inside_it(): void
    {
        // Both are `beban`; only the chart says which is cost of sales. Rent
        // does not belong in gross margin and volume does not move it.
        $this->trade(sale: 10_000_000, cost: 6_000_000);
        $this->expense(1_500_000);

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(4_000_000, $pl->labaKotor());
        $this->assertSame(1_500_000, $pl->totalBeban());
        $this->assertSame(2_500_000, $pl->labaBersih());
    }

    public function test_the_purchase_price_variance_is_cost_of_sales_not_overhead(): void
    {
        // It hangs under the HPP header in the chart, so it must land above
        // gross profit — a supplier charging more is a cost of trading.
        $this->trade(sale: 10_000_000, cost: 6_000_000);

        $this->journal(
            JournalDraft::manual('Selisih harga', new DateTime('2026-08-10'))
                ->debit(AccountCode::SELISIH_HARGA_PEMBELIAN, 300_000)
                ->kredit(AccountCode::UTANG_USAHA, 300_000)
        );

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(6_300_000, $pl->totalHargaPokok());
        $this->assertSame(3_700_000, $pl->labaKotor());
        $this->assertSame(0, $pl->totalBeban());
    }

    public function test_a_period_shows_only_what_happened_inside_it(): void
    {
        $this->trade(sale: 10_000_000, cost: 6_000_000, tanggal: '2026-07-15');
        $this->trade(sale: 20_000_000, cost: 12_000_000, tanggal: '2026-08-15');

        $juli = ProfitAndLoss::forPeriod(new DateTime('2026-07-01'), new DateTime('2026-07-31'));
        $agustus = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(10_000_000, $juli->totalPendapatan());
        $this->assertSame(20_000_000, $agustus->totalPendapatan());
    }

    public function test_a_period_includes_both_of_its_boundary_days(): void
    {
        $this->trade(sale: 1_000_000, cost: 0, tanggal: '2026-08-01');
        $this->trade(sale: 2_000_000, cost: 0, tanggal: '2026-08-31');
        $this->trade(sale: 4_000_000, cost: 0, tanggal: '2026-09-01');

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(3_000_000, $pl->totalPendapatan());
    }

    public function test_margin_is_reported_in_basis_points_so_nothing_needs_a_float(): void
    {
        $this->trade(sale: 10_000_000, cost: 6_000_000);

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(4_000, $pl->marginKotorBps());
    }

    public function test_margin_on_no_sales_is_absent_rather_than_zero(): void
    {
        // Zero would read as "we sold at cost". Nothing was sold.
        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertNull($pl->marginKotorBps());
        $this->assertSame(0, $pl->labaBersih());
    }

    public function test_a_loss_is_a_negative_result_not_an_absent_one(): void
    {
        $this->trade(sale: 5_000_000, cost: 6_000_000);
        $this->expense(1_000_000);

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-08-01'), new DateTime('2026-08-31'));

        $this->assertSame(-1_000_000, $pl->labaKotor());
        $this->assertSame(-2_000_000, $pl->labaBersih());
    }

    // -------------------------------------------------------------- neraca

    public function test_the_neraca_balances(): void
    {
        $this->capital(50_000_000);
        $this->trade(sale: 10_000_000, cost: 6_000_000);
        $this->expense(1_500_000);

        $neraca = BalanceSheet::asOf(new DateTime('2026-08-31'));

        $this->assertTrue($neraca->isBalanced(), "Selisih {$neraca->selisih()}");
        $this->assertSame(0, $neraca->selisih());
    }

    public function test_the_years_result_appears_in_equity_because_nothing_has_been_closed(): void
    {
        /*
         * Without this line the neraca is out by exactly the year's profit.
         * The result only becomes retained earnings at a period close, and
         * there has never been one — so it is computed and shown rather than
         * posted every time somebody opens a report.
         */
        $this->capital(50_000_000);
        $this->trade(sale: 10_000_000, cost: 6_000_000);

        $neraca = BalanceSheet::asOf(new DateTime('2026-08-31'));

        $this->assertSame(4_000_000, $neraca->labaTahunBerjalan);
        $this->assertSame(54_000_000, $neraca->totalModal());
        $this->assertTrue($neraca->isBalanced());

        $laba = $this->line($neraca->modal(), 'Laba tahun berjalan');
        $this->assertSame(4_000_000, $laba->amount);
        $this->assertTrue($laba->isComputed(), 'It has no account behind it, and must not pretend to.');
    }

    public function test_an_earlier_years_result_is_named_separately_from_this_years(): void
    {
        $this->capital(50_000_000, tanggal: '2025-01-05');
        $this->trade(sale: 10_000_000, cost: 6_000_000, tanggal: '2025-06-10');
        $this->trade(sale: 20_000_000, cost: 11_000_000, tanggal: '2026-06-10');

        $neraca = BalanceSheet::asOf(new DateTime('2026-08-31'));

        $this->assertSame(9_000_000, $neraca->labaTahunBerjalan);
        $this->assertSame(4_000_000, $neraca->labaDitahanBelumDitutup);
        $this->assertTrue($neraca->isBalanced());
        $this->assertSame(63_000_000, $neraca->totalModal());
    }

    public function test_a_first_year_shows_no_uncloseed_prior_line_at_all(): void
    {
        $this->capital(50_000_000);
        $this->trade(sale: 10_000_000, cost: 6_000_000);

        $labels = array_map(
            fn ($l) => $l->label,
            BalanceSheet::asOf(new DateTime('2026-08-31'))->modal()->lines,
        );

        $this->assertNotContains('Laba ditahan belum ditutup', $labels);
    }

    public function test_the_neraca_as_at_a_date_excludes_what_came_after(): void
    {
        $this->capital(50_000_000, tanggal: '2026-07-01');
        $this->trade(sale: 10_000_000, cost: 6_000_000, tanggal: '2026-07-15');
        $this->trade(sale: 90_000_000, cost: 50_000_000, tanggal: '2026-08-15');

        $juli = BalanceSheet::asOf(new DateTime('2026-07-31'));

        $this->assertSame(4_000_000, $juli->labaTahunBerjalan);
        $this->assertTrue($juli->isBalanced());
        $this->assertTrue(BalanceSheet::asOf(new DateTime('2026-08-31'))->isBalanced());
    }

    public function test_liabilities_and_equity_read_positive_on_a_balance_sheet(): void
    {
        // They are credit-balance accounts. Showing them negative because
        // debits minus credits is negative is the classic way this is wrong.
        $this->capital(50_000_000);
        $this->journal(
            JournalDraft::manual('Beli persediaan kredit', new DateTime('2026-08-05'))
                ->debit(AccountCode::PERSEDIAAN, 8_000_000)
                ->kredit(AccountCode::UTANG_USAHA, 8_000_000)
        );

        $neraca = BalanceSheet::asOf(new DateTime('2026-08-31'));

        $this->assertSame(8_000_000, $neraca->totalKewajiban());
        $this->assertSame(50_000_000, $neraca->totalModal());
        $this->assertSame(58_000_000, $neraca->totalAset());
    }

    public function test_the_neraca_carries_no_income_or_expense_accounts(): void
    {
        $this->capital(50_000_000);
        $this->trade(sale: 10_000_000, cost: 6_000_000);

        $codes = [];

        foreach ([$neraca = BalanceSheet::asOf(new DateTime('2026-08-31'))] as $n) {
            foreach ([$n->aset(), $n->kewajiban(), $n->modal()] as $section) {
                foreach ($section->lines as $line) {
                    $codes[] = $line->kode();
                }
            }
        }

        $this->assertNotContains(AccountCode::PENJUALAN, $codes);
        $this->assertNotContains(AccountCode::HARGA_POKOK_PENJUALAN, $codes);
    }

    public function test_the_two_statements_agree_on_the_years_result(): void
    {
        $this->capital(50_000_000);
        $this->trade(sale: 10_000_000, cost: 6_000_000);
        $this->expense(1_500_000);

        $pl = ProfitAndLoss::forPeriod(new DateTime('2026-01-01'), new DateTime('2026-08-31'));
        $neraca = BalanceSheet::asOf(new DateTime('2026-08-31'));

        $this->assertSame($pl->labaBersih(), $neraca->labaTahunBerjalan);
    }

    // --- helpers ------------------------------------------------------------

    private function journal(JournalDraft $draft): void
    {
        $this->ledger->postManual($draft, $this->finance);
    }

    private function capital(int $amount, string $tanggal = '2026-01-02'): void
    {
        $this->journal(
            JournalDraft::manual('Setoran modal', new DateTime($tanggal))
                ->debit(AccountCode::BANK, $amount)
                ->kredit(AccountCode::MODAL_DISETOR, $amount)
        );
    }

    /** A sale and its cost, on one day. */
    private function trade(int $sale, int $cost, string $tanggal = '2026-08-15'): void
    {
        if ($sale > 0) {
            $this->journal(
                JournalDraft::manual("Penjualan {$tanggal}", new DateTime($tanggal))
                    ->debit(AccountCode::PIUTANG_USAHA, $sale)
                    ->kredit(AccountCode::PENJUALAN, $sale)
            );
        }

        if ($cost > 0) {
            $this->journal(
                JournalDraft::manual("HPP {$tanggal}", new DateTime($tanggal))
                    ->debit(AccountCode::HARGA_POKOK_PENJUALAN, $cost)
                    ->kredit(AccountCode::PERSEDIAAN, $cost)
            );
        }
    }

    private function expense(int $amount, string $tanggal = '2026-08-20'): void
    {
        $this->journal(
            JournalDraft::manual('Beban operasional', new DateTime($tanggal))
                ->debit(AccountCode::BEBAN_OPERASIONAL, $amount)
                ->kredit(AccountCode::BANK, $amount)
        );
    }

    private function line(StatementSection $section, string $label): \App\Domain\Accounting\StatementLine
    {
        foreach ($section->lines as $line) {
            if ($line->label === $label) {
                return $line;
            }
        }

        $this->fail("Baris '{$label}' tidak ada di bagian {$section->label}.");
    }
}
