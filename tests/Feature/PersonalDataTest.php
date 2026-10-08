<?php

namespace Tests\Feature;

use App\Domain\Privacy\PersonalData;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Models\Company\AuditLog;
use App\Models\Company\Employee;
use App\Models\Purchasing\VendorBankAccount;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/** A person's data, exported for a request under the personal data protection law (docs/PRIVACY.md). */
class PersonalDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAsAdmin();
    }

    public function test_a_customer_vendor_or_employee_exports_what_is_held_about_them(): void
    {
        $customer = $this->sampleCustomer(['email' => 'buyer@example.test', 'credit_limit_amount' => 50_000_000]);
        $vendor = $this->sampleVendor();
        VendorBankAccount::query()->create(['vendor_id' => $vendor->id, 'bank_account' => '777-888']);
        $employee = Employee::query()->create(['number' => 'EMP-1', 'name' => 'Rina', 'nik_no' => '3201010190000021']);

        $data = PersonalData::of($customer);
        $this->assertSame('buyer@example.test', $data['record']['email']);
        $this->assertSame(50_000_000, (int) $data['record']['credit_limit_amount']);
        $this->assertArrayHasKey('invoices', $data);
        $this->assertSame('777-888', PersonalData::of($vendor)['bank_accounts'][0]['bank_account'], 'read in clear for the person it belongs to');
        $this->assertSame('3201010190000021', PersonalData::of($employee)['record']['nik_no']);

        // Credit data stays out for someone who may not see it.
        $clerk = User::factory()->create();
        AccessGroup::query()->where('name', 'Accounting')->firstOrFail()->users()->attach($clerk);
        $this->actingAs($clerk);
        $this->freshRequest();
        $this->assertArrayNotHasKey('credit_limit_amount', PersonalData::of($customer)['record']);
    }

    public function test_the_screen_downloads_it_and_logs_it(): void
    {
        $customer = $this->sampleCustomer();
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('exportPersonalData')
            ->assertFileDownloaded('personal-data-'.strtolower($customer->number).'.json');
        $this->assertSame(1, AuditLog::query()->where('action', 'personal_data_exported')->where('document_id', $customer->id)->count());

        // Without the export right there is no such button.
        $sales = User::factory()->create();
        AccessGroup::query()->where('name', 'Sales')->firstOrFail()->users()->attach($sales);
        $this->actingAs($sales);
        $this->freshRequest();
        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->assertActionHidden('exportPersonalData');
    }
}
