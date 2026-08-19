<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Billing\CustomerDepositRegister;
use App\Domain\Expenses\PaidFrom;
use App\Filament\Resources\CustomerDeposits\CustomerDepositResource;
use App\Filament\Resources\CustomerDeposits\Pages\ListCustomerDeposits;
use App\Filament\Resources\CustomerDeposits\Tables\CustomerDepositsTable;
use App\Models\Company;
use App\Models\CustomerDeposit;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerDepositScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->company = Company::factory()->creditLimit(100_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(CustomerDepositResource::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            // Sales sells; whoever agreed the price does not say money arrived.
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_taking_one_from_the_screen_records_it_as_a_liability(): void
    {
        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->callAction('catat', [
                'company_id' => $this->company->id,
                'tanggal' => now()->toDateString(),
                'jumlah_rupiah' => 10_000_000,
                'diterima_di' => PaidFrom::Bank->value,
                'referensi' => 'TRF-77',
            ])
            ->assertHasNoActionErrors();

        $deposit = CustomerDeposit::query()->sole();

        $this->assertSame(10_000_000, (int) $deposit->jumlah_rupiah);
        $this->assertSame(10_000_000, $deposit->sisaRupiah());
        $this->assertTrue($deposit->isHeld());
    }

    public function test_a_refusal_is_reported_rather_than_thrown(): void
    {
        // A future date is refused by the register; the action has to survive
        // it as a notification rather than a 500.
        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->callAction('catat', [
                'company_id' => $this->company->id,
                'tanggal' => now()->addDay()->toDateString(),
                'jumlah_rupiah' => 10_000_000,
                'diterima_di' => PaidFrom::Bank->value,
            ]);

        $this->assertSame(0, CustomerDeposit::query()->count());
    }

    public function test_using_it_from_the_screen_settles_the_invoice(): void
    {
        $deposit = $this->deposit(10_000_000);
        $invoice = $this->invoice(6_000_000);

        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->callTableAction('pakai', $deposit, [
                'invoice_id' => $invoice->id,
                'jumlah_rupiah' => 6_000_000,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(4_000_000, $deposit->refresh()->sisaRupiah());
    }

    public function test_refunding_from_the_screen_closes_it(): void
    {
        $deposit = $this->deposit(10_000_000);

        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->callTableAction('kembalikan', $deposit, [
                'jumlah_rupiah' => 10_000_000,
                'alasan' => 'Pesanan dibatalkan',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(CustomerDeposit::STATUS_CLOSED, $deposit->refresh()->status);
    }

    public function test_neither_action_is_offered_once_the_deposit_is_spent(): void
    {
        $deposit = $this->deposit(5_000_000);

        app(CustomerDepositRegister::class)->apply(
            $deposit,
            $this->invoice(5_000_000),
            5_000_000,
            $this->finance,
        );

        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->assertTableActionHidden('pakai', $deposit->refresh())
            ->assertTableActionHidden('kembalikan', $deposit->refresh());
    }

    public function test_using_it_is_offered_but_refused_when_nothing_is_open(): void
    {
        /*
         * A deposit taken before the order exists is the ordinary case, so a
         * customer with nothing open is common rather than exotic. The action
         * stays visible — hiding it leaves people hunting — but it cannot be
         * pressed, because the form behind it would have no invoice to offer.
         */
        $deposit = $this->deposit(5_000_000);

        $this->assertFalse(CustomerDepositsTable::hasOpenInvoice($deposit));

        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->assertTableActionVisible('pakai', $deposit)
            ->assertTableActionDisabled('pakai', $deposit);
    }

    public function test_using_it_becomes_pressable_once_an_invoice_exists(): void
    {
        $deposit = $this->deposit(5_000_000);
        $this->invoice(3_000_000);

        Livewire::actingAs($this->finance)
            ->test(ListCustomerDeposits::class)
            ->assertTableActionEnabled('pakai', $deposit);
    }

    public function test_a_deposit_can_never_be_edited_or_deleted(): void
    {
        /*
         * A deposit is money that already arrived. Editing the amount would
         * mean editing what the bank did — corrections are refunds.
         */
        $deposit = $this->deposit(5_000_000);

        $this->assertFalse(CustomerDepositResource::canEdit($deposit));
        $this->assertFalse(CustomerDepositResource::canDelete($deposit));
        $this->assertFalse(CustomerDepositResource::canCreate());
    }

    public function test_the_badge_counts_money_nobody_has_put_against_an_invoice(): void
    {
        $this->actingAs($this->finance);

        $this->assertNull(CustomerDepositResource::getNavigationBadge());

        $deposit = $this->deposit(5_000_000);
        $this->assertSame('1', CustomerDepositResource::getNavigationBadge());

        app(CustomerDepositRegister::class)->apply(
            $deposit,
            $this->invoice(5_000_000),
            5_000_000,
            $this->finance,
        );

        $this->assertNull(CustomerDepositResource::getNavigationBadge());
    }

    private function deposit(int $amount): CustomerDeposit
    {
        return app(CustomerDepositRegister::class)->receive(
            company: $this->company,
            jumlahRupiah: $amount,
            diterimaDi: PaidFrom::Bank,
            tanggal: Carbon::now(),
            actor: $this->finance,
        );
    }

    private function invoice(int $amount): Invoice
    {
        return Invoice::factory()->totalling($amount)->create([
            'company_id' => $this->company->id,
            'issued_on' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }
}
