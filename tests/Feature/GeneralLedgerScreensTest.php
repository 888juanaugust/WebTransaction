<?php

namespace Tests\Feature;

use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\PeriodLock;
use App\Domain\Posting\PostingService;
use App\Filament\Pages\GeneralLedger\AccountHistory;
use App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages\CreateExpenseAccrual;
use App\Filament\Resources\GeneralLedger\JournalVouchers\Pages\CreateJournalVoucher;
use App\Filament\Resources\GeneralLedger\JournalVouchers\Pages\EditJournalVoucher;
use App\Filament\Resources\GeneralLedger\JournalVouchers\Pages\ListJournalVouchers;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\ExpenseAccrual;
use App\Models\GeneralLedger\JournalEntry;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\GeneralLedger\Posting;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class GeneralLedgerScreensTest extends TestCase
{
    private Account $cash;

    private Account $rent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->cash = Account::query()->where('no', '1101')->firstOrFail();
        $this->rent = Account::query()->where('no', '6200')->firstOrFail();
    }

    public function test_a_journal_voucher_is_numbered_posted_and_shown_in_the_journal_list(): void
    {
        Livewire::test(CreateJournalVoucher::class)
            ->fillForm([
                'trans_date' => '2026-11-10',
                'description' => 'Rent paid in cash',
                'lines' => [
                    ['account_id' => $this->rent->id, 'debit' => 5_000_000, 'credit' => 0, 'memo' => 'November'],
                    ['account_id' => $this->cash->id, 'debit' => 0, 'credit' => 5_000_000],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $voucher = JournalVoucher::query()->firstOrFail();
        $this->assertSame('JV-2611-0001', $voucher->number);
        $this->assertSame(5_000_000, $voucher->total);
        $this->assertSame(1, Posting::active()->where('posting_key', $voucher->postingKey())->count());
        $this->assertSame(5_000_000, AccountBalances::asOf()[$this->rent->id]);
        $this->assertSame(-5_000_000, AccountBalances::asOf()[$this->cash->id]);
        $this->assertDatabaseHas('document_revisions', ['document_type' => 'journal_voucher', 'document_id' => $voucher->id, 'action' => 'created']);

        Livewire::test(ListJournalVouchers::class)
            ->assertCanSeeTableRecords(JournalEntry::query()->active()->get())
            ->assertSee('JV-2611-0001')
            ->assertSee('Rent paid in cash');

        Livewire::test(EditJournalVoucher::class, ['record' => $voucher->getRouteKey()])
            ->fillForm(['lines' => [
                ['account_id' => $this->rent->id, 'debit' => 6_000_000, 'credit' => 0],
                ['account_id' => $this->cash->id, 'debit' => 0, 'credit' => 6_000_000],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(6_000_000, AccountBalances::asOf()[$this->rent->id]);
        $this->assertSame(2, Posting::query()->where('posting_key', $voucher->postingKey())->count());
        $this->assertSame(1, Posting::active()->where('posting_key', $voucher->postingKey())->count());
    }

    public function test_an_unbalanced_voucher_is_refused_by_the_form(): void
    {
        Livewire::test(CreateJournalVoucher::class)
            ->fillForm([
                'trans_date' => '2026-11-10',
                'lines' => [
                    ['account_id' => $this->rent->id, 'debit' => 100, 'credit' => 0],
                    ['account_id' => $this->cash->id, 'debit' => 0, 'credit' => 90],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['lines']);

        $this->assertSame(0, JournalVoucher::query()->count());
    }

    public function test_a_voucher_in_a_closed_month_cannot_be_saved(): void
    {
        app(PeriodLock::class)->close(2026, 10);

        $voucher = JournalVoucher::query()->create(['number' => 'JV-X', 'trans_date' => '2026-11-01']);
        $voucher->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->rent->id, 'debit' => 100, 'credit' => 0],
            ['sort' => 1, 'account_id' => $this->cash->id, 'debit' => 0, 'credit' => 100],
        ]);

        Livewire::test(EditJournalVoucher::class, ['record' => $voucher->getRouteKey()])
            ->fillForm(['trans_date' => '2026-10-20'])
            ->call('save')
            ->assertNotified();

        $this->assertSame('2026-11-01', $voucher->fresh()->trans_date->toDateString(), 'the move into the closed month was refused');
    }

    public function test_an_expense_accrual_books_expenses_against_the_payable(): void
    {
        $payable = Account::query()->where('no', '2230')->firstOrFail();

        Livewire::test(CreateExpenseAccrual::class)
            ->fillForm([
                'payable_account_id' => $payable->id,
                'trans_date' => '2026-11-12',
                'due_date' => '2026-12-12',
                'description' => 'Office rent and electricity',
                'lines' => [
                    ['account_id' => $this->rent->id, 'amount' => 4_000_000],
                    ['account_id' => Account::query()->where('no', '6500')->value('id'), 'amount' => 750_000, 'memo' => 'PLN'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $accrual = ExpenseAccrual::query()->firstOrFail();
        $this->assertSame('EA-2611-0001', $accrual->number);
        $this->assertSame(4_750_000, $accrual->total);
        $this->assertSame('unpaid', $accrual->status);
        $this->assertSame(4_750_000, AccountBalances::asOf()[$payable->id]);
        $this->assertSame(4_000_000, AccountBalances::asOf()[$this->rent->id]);
    }

    public function test_account_history_runs_a_balance(): void
    {
        $voucher = JournalVoucher::query()->create(['number' => 'JV-1', 'trans_date' => '2026-11-03', 'description' => 'first']);
        $voucher->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->cash->id, 'debit' => 1_000_000, 'credit' => 0],
            ['sort' => 1, 'account_id' => $this->rent->id, 'debit' => 0, 'credit' => 1_000_000],
        ]);
        app(PostingService::class)->post($voucher->fresh());
        $second = JournalVoucher::query()->create(['number' => 'JV-2', 'trans_date' => '2026-11-05', 'description' => 'second']);
        $second->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->rent->id, 'debit' => 250_000, 'credit' => 0],
            ['sort' => 1, 'account_id' => $this->cash->id, 'debit' => 0, 'credit' => 250_000],
        ]);
        app(PostingService::class)->post($second->fresh());

        Livewire::test(AccountHistory::class)
            ->fillForm(['account_id' => $this->cash->id, 'from' => '2026-11-04', 'until' => '2026-11-30'])
            ->assertSee('Opening balance')
            ->assertSee('1.000.000')
            ->assertSee('JV-2')
            ->assertSee('750.000');
    }
}
