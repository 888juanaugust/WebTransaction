<?php

namespace Tests\Feature;

use App\Domain\Posting\AccountBalances;
use App\Filament\Resources\CashBank\BankTransfers\Pages\CreateBankTransfer;
use App\Filament\Resources\CashBank\CashPayments\Pages\CreateCashPayment;
use App\Filament\Resources\CashBank\CashPayments\Pages\ListCashPayments;
use App\Filament\Resources\CashBank\CashReceipts\Pages\CreateCashReceipt;
use App\Models\CashBank\BankTransfer;
use App\Models\CashBank\CashPayment;
use App\Models\CashBank\CashReceipt;
use App\Models\CashBank\Giro;
use App\Models\GeneralLedger\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CashBankScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[$this->account($no)] ?? 0;
    }

    public function test_receipts_payments_and_transfers_run_through_the_screens(): void
    {
        Livewire::test(CreateCashReceipt::class)
            ->fillForm([
                'bank_account_id' => $this->account('1102'),
                'trans_date' => '2026-11-01',
                'payer' => 'Owner',
                'lines' => [['account_id' => $this->account('3100'), 'amount' => 20_000_000, 'memo' => 'Paid-in capital']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = CashReceipt::query()->firstOrFail();
        $this->assertSame('CB-2611-0001', $receipt->number);
        $this->assertSame(20_000_000, $receipt->amount);
        $this->assertSame(20_000_000, $this->balance('1102'));
        $this->assertNull($receipt->giro);

        Livewire::test(CreateCashPayment::class)
            ->fillForm([
                'bank_account_id' => $this->account('1102'),
                'trans_date' => '2026-11-02',
                'payee' => 'Landlord',
                'cheque_no' => 'CK-1',
                'cheque_date' => '2026-11-15',
                'lines' => [['account_id' => $this->account('6200'), 'amount' => 5_000_000, 'memo' => 'November rent']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = CashPayment::query()->firstOrFail();
        $this->assertSame('CB-2611-0002', $payment->number);
        $this->assertSame(Giro::OUTSTANDING, $payment->giro->status);
        $this->assertSame(5_000_000, $this->balance('2105'), 'paid by giro: giros payable until it clears');
        $this->assertSame(20_000_000, $this->balance('1102'));

        Livewire::test(ListCashPayments::class)->assertTableActionHidden('giroCleared', $payment); // not for whoever entered it
        $this->actingAsAdmin();
        Livewire::test(ListCashPayments::class)
            ->assertTableActionVisible('giroCleared', $payment)
            ->callTableAction('giroCleared', $payment, ['on' => '2026-11-15'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(Giro::CLEARED, $payment->giro->fresh()->status);
        $this->assertSame(0, $this->balance('2105'));
        $this->assertSame(15_000_000, $this->balance('1102'));
        Livewire::test(ListCashPayments::class)->assertTableActionHidden('giroCleared', $payment);

        Livewire::test(CreateBankTransfer::class)
            ->fillForm([
                'trans_date' => '2026-11-05',
                'from_bank_account_id' => $this->account('1102'),
                'to_bank_account_id' => $this->account('1102'),
                'amount' => 5_000_000,
            ])
            ->call('create')
            ->assertHasFormErrors(['to_bank_account_id']);

        Livewire::test(CreateBankTransfer::class)
            ->fillForm([
                'trans_date' => '2026-11-05',
                'from_bank_account_id' => $this->account('1102'),
                'to_bank_account_id' => $this->account('1101'),
                'amount' => 5_000_000,
                'fees' => [['account_id' => $this->account('8100'), 'charged_to' => 'from', 'amount' => 2_500, 'memo' => 'Fee']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $transfer = BankTransfer::query()->firstOrFail();
        $this->assertSame('BT-2611-0001', $transfer->number);
        $this->assertSame(2_500, $transfer->fees_total);
        $this->assertSame(15_000_000 - 5_000_000 - 2_500, $this->balance('1102'));
        $this->assertSame(5_000_000, $this->balance('1101'));
        $this->assertSame(2_500, $this->balance('8100'));
    }
}
