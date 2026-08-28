<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\AccountCode;
use App\Domain\Expenses\ClaimStatus;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Expenses\SalesExpenseClaims;
use App\Filament\Resources\SalesExpenseClaims\SalesExpenseClaimResource;
use App\Filament\Widgets\ExpenseClaimsAwaitingVerification;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Biaya ekspedisi — sales' road spending, verified by hand before it is a
 * cost.
 *
 * The claims pinned here: only a sales files, only for themselves; nothing
 * reaches the books until finance approves; the approval is an ordinary
 * expense document on the ongkos-kirim account; and a rejection carries a
 * reason the sales will read.
 */
class SalesExpenseClaimTest extends TestCase
{
    use RefreshDatabase;

    private User $sales;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->finance = User::factory()->finance()->create(['region_id' => $this->currentRegion()->id]);
    }

    private function claims(): SalesExpenseClaims
    {
        return app(SalesExpenseClaims::class);
    }

    public function test_a_sales_files_a_claim_and_nothing_reaches_the_books(): void
    {
        $claim = $this->claims()->file($this->sales, today(), 150_000, 'Bensin dan tol rute Bekasi.');

        $this->assertSame(ClaimStatus::Diajukan, $claim->status);
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'sales_expense_claimed')->count());
    }

    public function test_only_a_sales_may_file(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/diajukan oleh sales/');

        $this->claims()->file($this->finance, today(), 150_000, 'Bensin.');
    }

    public function test_a_claim_needs_a_note_and_a_positive_amount(): void
    {
        try {
            $this->claims()->file($this->sales, today(), 0, 'Bensin.');
            $this->fail('A zero claim was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('lebih dari nol', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/untuk apa uangnya/');

        $this->claims()->file($this->sales, today(), 150_000, '  ');
    }

    public function test_finance_approval_posts_the_expense_into_the_books(): void
    {
        $claim = $this->claims()->file($this->sales, today(), 150_000, 'Bensin dan tol rute Bekasi.');

        $claim = $this->claims()->approve($claim, $this->finance, PaidFrom::Kas, 'Struk cocok.');

        $this->assertSame(ClaimStatus::Disetujui, $claim->status);
        $this->assertNotNull($claim->expense_id);

        // An ordinary expense document, on the ongkos-kirim account, with the
        // journal behind it — exactly what finance keying it in by hand
        // would have produced.
        $expense = $claim->expense;
        $this->assertSame(150_000, $expense->amount_rupiah);
        $this->assertSame(
            AccountCode::BEBAN_ONGKOS_KIRIM,
            Account::query()->find($expense->account_id)->kode,
        );
        $this->assertStringContainsString($this->sales->name, $expense->keterangan);
        $this->assertGreaterThan(0, JournalEntry::query()->count());
    }

    public function test_the_sales_cannot_verify_their_own_claim(): void
    {
        $claim = $this->claims()->file($this->sales, today(), 150_000, 'Bensin.');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/keputusan finance/');

        $this->claims()->approve($claim, $this->sales, PaidFrom::Kas);
    }

    public function test_rejection_needs_a_reason_and_is_terminal(): void
    {
        $claim = $this->claims()->file($this->sales, today(), 150_000, 'Bensin.');

        try {
            $this->claims()->reject($claim, $this->finance, ' ');
            $this->fail('A reasonless rejection was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kenapa ditolak', $e->getMessage());
        }

        $this->claims()->reject($claim, $this->finance, 'Tidak ada struk.');
        $this->assertSame(ClaimStatus::Ditolak, $claim->refresh()->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah Ditolak/');
        $this->claims()->approve($claim->refresh(), $this->finance, PaidFrom::Kas);
    }

    public function test_a_sales_reads_only_their_own_claims(): void
    {
        $milikku = $this->claims()->file($this->sales, today(), 100_000, 'Bensin.');

        $lain = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->claims()->file($lain, today(), 200_000, 'Parkir.');

        $this->actingAs($this->sales, 'web');

        $this->assertSame(
            [$milikku->id],
            SalesExpenseClaimResource::getEloquentQuery()->pluck('id')->all(),
        );

        // Finance reads them all — theirs is the queue.
        $this->actingAs($this->finance, 'web');
        $this->assertSame(2, SalesExpenseClaimResource::getEloquentQuery()->count());
    }

    public function test_the_verification_queue_belongs_to_finance(): void
    {
        $this->actingAs($this->finance, 'web');
        $this->assertTrue(ExpenseClaimsAwaitingVerification::canView());

        $this->actingAs($this->sales, 'web');
        $this->assertFalse(ExpenseClaimsAwaitingVerification::canView());
    }
}
