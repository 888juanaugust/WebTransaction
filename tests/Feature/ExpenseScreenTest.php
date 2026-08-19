<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Expenses\ExpenseRecorder;
use App\Domain\Expenses\PaidFrom;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = User::factory()->role(Role::Finance)->create();
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(ExpenseResource::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_recording_one_from_the_screen_posts_it(): void
    {
        Livewire::actingAs($this->finance)
            ->test(ListExpenses::class)
            ->callAction('catat', [
                'tanggal' => now()->toDateString(),
                'account' => AccountCode::BEBAN_SEWA,
                'amount_rupiah' => 12_000_000,
                'dibayar_dari' => PaidFrom::Bank->value,
                'keterangan' => 'Sewa gudang Agustus',
            ])
            ->assertHasNoActionErrors();

        $expense = Expense::query()->sole();

        $this->assertSame(12_000_000, (int) $expense->amount_rupiah);
        $this->assertSame(AccountCode::BEBAN_SEWA, $expense->account->kode);
    }

    public function test_the_form_offers_no_account_the_domain_would_refuse(): void
    {
        /*
         * Offering a choice that is then rejected is a screen that lied. The
         * recorder refuses cost of sales and non-expense accounts, so neither
         * may appear in the picker.
         */
        $offered = array_keys(ListExpenses::expenseAccounts());

        $this->assertContains(AccountCode::BEBAN_GAJI, $offered);
        $this->assertContains(AccountCode::BEBAN_ADMIN_BANK, $offered);

        $this->assertNotContains(AccountCode::HARGA_POKOK_PENJUALAN, $offered);
        $this->assertNotContains(AccountCode::SELISIH_HARGA_PEMBELIAN, $offered);
        $this->assertNotContains(AccountCode::PERSEDIAAN, $offered);
        $this->assertNotContains('6-0000', $offered);
    }

    public function test_a_recorded_expense_cannot_be_edited_or_deleted(): void
    {
        $expense = app(ExpenseRecorder::class)->record(
            now(), AccountCode::BEBAN_SEWA, PaidFrom::Bank, 1_000_000, 'Sewa', $this->finance,
        );

        $this->assertFalse(ExpenseResource::canEdit($expense));
        $this->assertFalse(ExpenseResource::canDelete($expense));
        $this->assertFalse(ExpenseResource::canCreate());
    }

    public function test_correcting_is_offered_once_and_then_not_again(): void
    {
        $recorder = app(ExpenseRecorder::class);

        $expense = $recorder->record(
            now(), AccountCode::BEBAN_SEWA, PaidFrom::Bank, 1_000_000, 'Sewa', $this->finance,
        );

        Livewire::actingAs($this->finance)
            ->test(ListExpenses::class)
            ->assertTableActionVisible('koreksi', $expense);

        $reversal = $recorder->reverse($expense, $this->finance, 'Salah akun');

        Livewire::actingAs($this->finance)
            ->test(ListExpenses::class)
            ->assertTableActionHidden('koreksi', $expense->refresh())
            ->assertTableActionHidden('koreksi', $reversal);
    }

    public function test_a_refusal_is_reported_rather_than_thrown(): void
    {
        // The date picker hands back a string and the domain refuses future
        // dates; the action must survive that as a notification, not a 500.
        Livewire::actingAs($this->finance)
            ->test(ListExpenses::class)
            ->callAction('catat', [
                'tanggal' => now()->toDateString(),
                'account' => AccountCode::HARGA_POKOK_PENJUALAN,
                'amount_rupiah' => 1_000_000,
                'dibayar_dari' => PaidFrom::Bank->value,
                'keterangan' => 'Percobaan',
            ]);

        $this->assertSame(0, Expense::query()->count());
    }
}
