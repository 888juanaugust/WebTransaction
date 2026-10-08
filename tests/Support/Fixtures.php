<?php

namespace Tests\Support;

use App\Models\Company\Branch;
use App\Models\Company\Department;
use App\Models\Company\Employee;
use App\Models\Company\PaymentTerm;
use App\Models\Company\Project;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;

/** The one customer, vendor, item, salesperson, department and project most tests need, on the seeded defaults; pass attributes to vary them. */
trait Fixtures
{
    /** @param  array<string, mixed>  $attributes */
    protected function sampleCustomer(array $attributes = []): Customer
    {
        return Customer::query()->create(array_merge([
            'number' => 'C-00001',
            'name' => 'Acme Trading',
            'price_category_id' => PriceCategory::query()->where('is_default', true)->value('id'),
            'payment_term_id' => PaymentTerm::default()?->id,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function sampleVendor(array $attributes = []): Vendor
    {
        return Vendor::query()->create(array_merge([
            'number' => 'V-00001',
            'name' => 'Contoso Supplies',
            'branch_id' => Branch::default()?->id,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function sampleItem(array $attributes = []): Item
    {
        return Item::query()->create(array_merge([
            'number' => 'ITM-00001',
            'name' => 'Widget',
            'unit1_id' => Unit::query()->where('name', 'PCS')->value('id'),
            'sell_price' => 150_000,
            'purchase_price' => 100_000,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function sampleEmployee(array $attributes = []): Employee
    {
        return Employee::query()->create(array_merge([
            'number' => 'EMP-00001',
            'name' => 'Alex Doe',
            'is_salesman' => true,
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function sampleDepartment(array $attributes = []): Department
    {
        return Department::query()->create(array_merge([
            'code' => 'D-SALES',
            'name' => 'Sales',
        ], $attributes));
    }

    /** @param  array<string, mixed>  $attributes */
    protected function sampleProject(array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'code' => 'P-001',
            'name' => 'Warehouse fit-out',
            'start_date' => '2026-11-01',
            'status' => 'active',
        ], $attributes));
    }
}
