<?php

namespace Tests\Feature;

use App\Domain\CashBank\Reconciler;
use App\Domain\CashBank\StatementImporter;
use App\Domain\Posting\DocumentRepository;
use App\Filament\Pages\CashBank\BankBook;
use App\Filament\Pages\CashBank\BankReconciliation;
use App\Filament\Pages\CashBank\BankStatements;
use App\Models\CashBank\BankReconciliation as Reconciliation;
use App\Models\CashBank\BankTransfer;
use App\Models\CashBank\CashReceipt;
use App\Models\GeneralLedger\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CashBankPagesTest extends TestCase
{
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->bank = (int) Account::query()->where('no', '1102')->value('id');

        $docs = app(DocumentRepository::class);
        $receipt = CashReceipt::query()->create(['number' => 'CR-1', 'trans_date' => '2026-11-01', 'bank_account_id' => $this->bank, 'cheque_no' => null, 'payer' => 'Owner', 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'account_id' => Account::query()->where('no', '3100')->value('id'), 'amount' => 20_000_000, 'memo' => 'Paid-in capital']);
        $receipt->refreshTotal();
        $docs->created($receipt);

        $transfer = BankTransfer::query()->create(['number' => 'BT-1', 'trans_date' => '2026-11-05', 'from_bank_account_id' => $this->bank, 'to_bank_account_id' => Account::query()->where('no', '1101')->value('id'), 'amount' => 10_000_000, 'created_by' => auth()->id()]);
        $transfer->fees()->create(['sort' => 0, 'account_id' => Account::query()->where('no', '8100')->value('id'), 'charged_to' => 'from', 'amount' => 6_500]);
        $transfer->refreshTotal();
        $docs->created($transfer);
    }

    public function test_the_bank_book_runs_a_balance_through_the_account(): void
    {
        Livewire::test(BankBook::class)
            ->set('filters.bank_account_id', $this->bank)
            ->set('filters.from', '2026-11-01')
            ->set('filters.until', '2026-11-30')
            ->assertSee('Opening balance')
            ->assertSee('CR-1')
            ->assertSee('BT-1')
            ->assertSee('20.000.000')
            ->assertSee('9.993.500');
    }

    public function test_the_statement_screen_lists_imported_lines_and_the_reconciliation_closes_when_matched(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        file_put_contents($csv, implode("\n", [
            'Tanggal;Keterangan;Debet;Kredit;Saldo',
            '01/11/2026;SETORAN MODAL;;20.000.000,00;20.000.000,00',
            '06/11/2026;BIAYA ADM TRANSFER;6.500,00;;19.993.500,00',
            '06/11/2026;TRF KE KAS KECIL;10.000.000,00;;9.993.500,00',
        ]));
        app(StatementImporter::class)->import($this->bank, $csv, 'nov.csv');

        Livewire::test(BankStatements::class)
            ->set('filters.bank_account_id', $this->bank)
            ->set('filters.from', '2026-11-01')
            ->set('filters.until', '2026-11-30')
            ->assertSee('SETORAN MODAL')
            ->assertSee('TRF KE KAS KECIL')
            ->assertSee('9.993.500');

        $page = Livewire::test(BankReconciliation::class)
            ->set('filters.bank_account_id', $this->bank)
            ->set('filters.start', '2026-11-01')
            ->set('filters.end', '2026-11-30')
            ->set('filters.statement_balance', 9_993_500)
            ->assertSee('CR-1')
            ->assertSee('BT-1');

        $page->callAction('close')->assertNotified('Cannot close');
        $this->assertSame(Reconciliation::OPEN, Reconciliation::query()->firstOrFail()->status);

        $this->actingAsAdmin(); // the lines were entered by the first user: someone else clears them
        $page = Livewire::test(BankReconciliation::class)
            ->set('filters.bank_account_id', $this->bank)
            ->set('filters.start', '2026-11-01')
            ->set('filters.end', '2026-11-30')
            ->set('filters.statement_balance', 9_993_500);
        $page->callAction('autoMatch')->assertHasNoActionErrors();
        $rec = Reconciliation::query()->firstOrFail();
        $this->assertSame(0, app(Reconciler::class)->summary($rec)['difference']);

        $page->callAction('close')->assertHasNoActionErrors();
        $this->assertTrue($rec->fresh()->isClosed());
    }
}
