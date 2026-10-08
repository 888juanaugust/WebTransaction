<?php

namespace Tests\Feature;

use App\Domain\Access\MenuKey;
use App\Domain\Posting\AccountBalances;
use App\Domain\Shared\Enums\PtkpStatus;
use App\Domain\Shared\Enums\WorkStatus;
use App\Filament\Pages\Reports\IncomeTaxArt21Return;
use App\Filament\Pages\Reports\WithholdingSlips;
use App\Filament\Resources\Company\Employees\Pages\EditEmployee;
use App\Filament\Resources\GeneralLedger\PayrollEntries\Pages\CreatePayrollEntry;
use App\Models\Company\Employee;
use App\Models\Company\PayrollEntry;
use App\Models\Company\SalaryComponent;
use App\Models\GeneralLedger\Account;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Payroll through the screens: the pay setup, "Calculate payroll", the Art. 21 return and the A1 slips. */
class PayrollScreensTest extends TestCase
{
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $this->enableAllModules();
        $this->seed();
        $this->actingAsAdmin();
        Storage::fake('local');
        $this->employee = Employee::query()->create(['number' => 'EMP-00021', 'name' => 'Rina Payroll', 'nik_no' => '3201010190000021', 'position' => 'Clerk', 'is_salesman' => false,
            'withhold_income_tax' => true, 'work_status' => WorkStatus::Permanent, 'tax_status' => PtkpStatus::TK0, 'join_date' => '2025-01-01']);
    }

    public function test_the_pay_setup_and_calculate_payroll(): void
    {
        $salary = SalaryComponent::query()->where('fee_type', 'salary')->firstOrFail();
        Livewire::test(EditEmployee::class, ['record' => $this->employee->getRouteKey()])
            ->fillForm(['salaryComponents' => [['salary_component_id' => $salary->id, 'amount' => 8_000_000]], 'bpjs_health' => true, 'bpjs_employment' => false, 'jp_participant' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(8_000_000, (int) $this->employee->salaryComponents()->sole()->amount);

        Livewire::test(CreatePayrollEntry::class)
            ->fillForm(['payment_type' => 'monthly', 'period_month' => 11, 'period_year' => 2026, 'trans_date' => '2026-11-25', 'due_date' => '2026-11-30'])
            ->callAction(TestAction::make('calculatePayroll')->schemaComponent(true, 'form'))
            ->call('create')
            ->assertHasNoFormErrors();

        // 8 m salary + 320 k health (employer) = 8,320,000 → TER A 1.5 % = 124,800; health 1 % (80 k) comes off the pay.
        $entry = PayrollEntry::query()->with('lines')->firstOrFail();
        $this->assertSame(3, $entry->lines->count(), 'salary, and both health shares');
        $this->assertSame(124_800, $entry->tax_total);
        $this->assertSame(8_000_000 - 124_800 - 80_000, $entry->total);
        $this->assertSame(400_000, AccountBalances::asOf()[(int) Account::query()->where('no', '2240')->value('id')], 'BPJS owed: 4 % and 1 %');

        Livewire::test(IncomeTaxArt21Return::class)
            ->set('filters.year', 2026)->set('filters.month', 11)
            ->assertSee('Rina Payroll')->assertSee('8.320.000')->assertSee('124.800')
            ->callAction('exportBpmp')
            ->assertNotified();
        $this->assertCount(1, Storage::disk('local')->allFiles('tax-filings'));

        Livewire::test(WithholdingSlips::class)->set('filters.year', 2026)->assertSee('Rina Payroll')->assertSee('Year still running');
        $this->get(route('filament.admin.a1', ['employee' => $this->employee->id, 'year' => 2026]))
            ->assertOk()->assertSee('Withholding slip A1')->assertSee('Rina Payroll')->assertSee('8.320.000');
    }

    public function test_the_a1_print_needs_the_print_right(): void
    {
        $readers = AccessGroup::query()->create(['name' => 'Slip readers']);
        $readers->syncRights([MenuKey::WithholdingSlips->value => ['view']]);
        $user = User::factory()->create();
        $readers->users()->attach($user);
        $this->actingAs($user);
        $this->freshRequest();

        $this->get(route('filament.admin.a1', ['employee' => $this->employee->id, 'year' => 2026]))->assertForbidden();
        $this->get(WithholdingSlips::getUrl())->assertOk();
        $this->get(IncomeTaxArt21Return::getUrl())->assertForbidden();
    }
}
