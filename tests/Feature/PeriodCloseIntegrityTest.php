<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\PeriodCloser;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use DateTime;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A month is the moment figures stop being provisional.
 *
 * `PeriodCloser` checked that months close oldest first, that the month had
 * ended, and that it was not closed already — and nothing about whether the
 * numbers being frozen were true. Closing over a control account that has left
 * its subledger locks in a figure nobody can explain, and unlocking it again
 * is Owner-only, so the cheap moment to notice is before the button.
 *
 * The line drawn here matters as much as the check. A drifted **book** figure
 * blocks: it makes the neraca wrong. A drifted **stock quantity** does not: it
 * makes the warehouse wrong and no journal untrue, and stopping the accountant
 * for a warehouse problem they cannot fix is how a guard gets overridden every
 * month until it means nothing.
 */
class PeriodCloseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private PeriodCloser $closer;

    private Ledger $ledger;

    private User $finance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2027-03-15 09:00:00');

        $this->seed(ChartOfAccountsSeeder::class);

        $this->closer = app(PeriodCloser::class);
        $this->ledger = app(Ledger::class);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A cash sale: real trade, no control account involved. */
    private function cashSale(string $tanggal, int $amount): void
    {
        $this->ledger->postManual(
            JournalDraft::manual("Penjualan tunai {$tanggal}", new DateTime($tanggal))
                ->debit(AccountCode::KAS, $amount)
                ->kredit(AccountCode::PENJUALAN, $amount),
            $this->finance,
        );
    }

    /**
     * A receivable on the books with no invoice behind it — the shape of every
     * real control-account drift: a journal posted by hand, or a subledger
     * written outside the domain.
     */
    private function phantomReceivable(int $amount): void
    {
        $this->ledger->postManual(
            JournalDraft::manual('Piutang tanpa faktur', new DateTime('2026-01-20'))
                ->debit(AccountCode::PIUTANG_USAHA, $amount)
                ->kredit(AccountCode::PENJUALAN, $amount),
            $this->finance,
        );
    }

    // --- the gate ----------------------------------------------------------

    public function test_a_month_whose_books_agree_still_closes(): void
    {
        $this->cashSale('2026-01-10', 10_000_000);

        $period = $this->closer->close(2026, 1, $this->finance);

        $this->assertInstanceOf(AccountingPeriod::class, $period);
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_finance_cannot_close_over_a_control_account_that_drifted(): void
    {
        $this->phantomReceivable(10_000_000);

        try {
            $this->closer->close(2026, 1, $this->finance);
            $this->fail('A month was closed over a drifting control account.');
        } catch (DomainException $e) {
            // The refusal names the account and what to do, not just "no".
            $this->assertStringContainsString('Piutang Usaha', $e->getMessage());
            $this->assertStringContainsString('Rp 10.000.000', $e->getMessage());
            $this->assertStringContainsString('pemilik', $e->getMessage());
        }

        $this->assertDatabaseCount('accounting_periods', 0);
    }

    /**
     * Finance may not force it even by supplying a reason. The two-tier shape
     * matches reopening: whoever is under pressure to publish a figure is not
     * the one who can wave the check aside alone.
     */
    public function test_a_reason_does_not_let_finance_through(): void
    {
        $this->phantomReceivable(10_000_000);

        $this->expectException(DomainException::class);

        $this->closer->close(2026, 1, $this->finance, null, 'Sudah dicek, nanti dibetulkan.');
    }

    public function test_the_owner_is_refused_too_until_they_say_why(): void
    {
        $this->phantomReceivable(10_000_000);

        try {
            $this->closer->close(2026, 1, $this->owner);
            $this->fail('The Owner closed over a finding with no reason given.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('alasannya harus ditulis', $e->getMessage());
        }

        $this->assertDatabaseCount('accounting_periods', 0);
    }

    public function test_the_owner_closes_over_a_finding_with_a_reason_and_it_is_audited(): void
    {
        $this->phantomReceivable(10_000_000);

        $period = $this->closer->close(
            2026, 1, $this->owner, null, 'Selisih warisan migrasi, dijelaskan di memo 12 Maret.',
        );

        $this->assertInstanceOf(AccountingPeriod::class, $period);

        $override = AuditLog::query()
            ->where('action', 'accounting_period_closed_over_findings')
            ->sole();

        $this->assertSame($this->owner->id, (int) $override->actor_id);
        $this->assertSame('Selisih warisan migrasi, dijelaskan di memo 12 Maret.', $override->alasan);

        // What was overridden is written out, so the question "what exactly
        // did they sign off" does not depend on re-running the check later
        // against data that has moved.
        $this->assertStringContainsString('Piutang Usaha', json_encode($override->old_value));

        // The ordinary close is still logged as itself.
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting_period_closed']);
    }

    public function test_a_clean_close_writes_no_override_row(): void
    {
        $this->cashSale('2026-01-10', 10_000_000);

        $this->closer->close(2026, 1, $this->finance);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'accounting_period_closed_over_findings',
        ]);
    }

    // --- the line between blocking and warning -----------------------------

    /**
     * A quantity cache that drifted makes the warehouse wrong, not the books.
     * It appears on the dashboard and in `integritas:periksa`; it does not
     * stand between the accountant and the month end.
     */
    public function test_a_stock_quantity_drift_does_not_block_the_close(): void
    {
        $this->cashSale('2026-01-10', 10_000_000);

        $gudang = Warehouse::factory()->create(['kode' => 'GD-HOME']);
        Product::factory()->create(['kode' => 'PC-1']);

        app(StockLedger::class)->record(
            sku: 'PC-1',
            warehouseId: $gudang->id,
            qtySigned: 10,
            reason: MovementReason::Penerimaan,
            actor: $this->finance,
        );

        DB::table('stock_levels')->where('sku', 'PC-1')->update(['qty_on_hand' => 17]);

        $period = $this->closer->close(2026, 1, $this->finance);

        $this->assertInstanceOf(AccountingPeriod::class, $period);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'accounting_period_closed_over_findings',
        ]);
    }

    // --- ordering of the guards --------------------------------------------

    /**
     * A month that has not ended yet cannot be closed whatever the books say,
     * and being told about a control account when the real problem is the
     * calendar buries the answer.
     */
    public function test_the_calendar_is_answered_before_the_books_are(): void
    {
        $this->phantomReceivable(10_000_000);

        try {
            // March 2027 is the month we are standing in.
            $this->closer->close(2027, 3, $this->owner);
            $this->fail('The current month was closed.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('belum berakhir', $e->getMessage());
            $this->assertStringNotContainsString('Piutang Usaha', $e->getMessage());
        }
    }
}
