<?php

namespace Tests\Feature;

use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\RecordInUse;
use App\Filament\Resources\Company\Departments\Pages\ManageDepartments;
use App\Filament\Resources\Company\TaxCodes\Pages\ManageTaxCodes;
use App\Filament\Resources\Inventory\Items\Pages\ListItems;
use App\Filament\Resources\Inventory\Units\Pages\ManageUnits;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Filament\Resources\Sales\SalesInvoices\Pages\EditSalesInvoice;
use App\Models\Company\Department;
use App\Models\Company\TaxCode;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\InvoiceExchange;
use App\Models\Sales\SalesInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** A record something else still uses is refused, with where it is used; nothing is half deleted. */
class InUseDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();
    }

    /** The refusal just sent, read (and cleared) from the session the way the notifications component reads it. */
    private function refusal(): string
    {
        $sent = collect(session()->pull('filament.claimed_notifications') ?? session()->pull('filament.notifications', []));
        $this->assertSame(['Cannot delete'], $sent->pluck('title')->all());

        return (string) $sent->first()['body'];
    }

    private function invoice(Customer $customer, Item $item, string $number = 'INV-1'): SalesInvoice
    {
        $invoice = SalesInvoice::query()->create(['number' => $number, 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 1, 'unit_id' => $item->unit1_id, 'base_quantity' => 1, 'unit_price' => 1_000_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);

        return $invoice;
    }

    public function test_a_customer_on_an_invoice_is_refused_and_one_never_used_is_deleted_with_its_addresses(): void
    {
        $customer = $this->sampleCustomer();
        $customer->addresses()->create(['address' => 'Jl. Contoh 1']);
        $this->invoice($customer, $this->sampleItem(['item_type' => 'service']));

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])->callAction('delete')->assertNoRedirect();
        $this->assertSame('C-00001 Acme Trading is used on Sales Invoices, so it cannot be deleted. Deactivate it instead: it stays on what already uses it and leaves the pick lists.', $this->refusal());
        $this->assertNotNull($customer->fresh());
        $this->assertSame(1, $customer->addresses()->count(), 'the refused delete took nothing with it');

        $unused = $this->sampleCustomer(['number' => 'C-00002', 'name' => 'Never bought']);
        $unused->addresses()->create(['address' => 'Jl. Contoh 2']);
        Livewire::test(EditCustomer::class, ['record' => $unused->getRouteKey()])->callAction('delete')->assertRedirect();
        $this->assertNull($unused->fresh());
    }

    public function test_masters_name_every_screen_that_uses_them(): void
    {
        $item = $this->sampleItem();
        $this->invoice($this->sampleCustomer(), $this->sampleItem(['number' => 'SVC-1', 'name' => 'Service', 'item_type' => 'service']));
        $stocked = Item::query()->create(['number' => 'ITM-2', 'name' => 'Gadget', 'unit1_id' => $item->unit1_id]);
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-1', 'trans_date' => '2026-11-01', 'created_by' => auth()->id()]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $stocked->id, 'adjustment_type' => 'quantity', 'quantity' => 5, 'unit_id' => $stocked->unit1_id, 'base_quantity' => 5, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        app(DocumentRepository::class)->created($adjustment);
        $this->invoice($this->sampleCustomer(['number' => 'C-00002']), $stocked, 'INV-2');

        Livewire::test(ListItems::class)->callTableAction('delete', $stocked->fresh());
        $this->assertStringStartsWith('ITM-2 Gadget is used on Inventory Adjustments, Sales Invoices, the stock ledger, so it cannot be deleted.', $this->refusal());
        $this->assertNotNull($stocked->fresh());

        $pcs = Unit::query()->where('name', 'PCS')->firstOrFail();
        Livewire::test(ManageUnits::class)->callTableAction('delete', $pcs);
        $this->assertSame('PCS is used on Inventory Adjustments, Items & Services, Sales Invoices, so it cannot be deleted.', $this->refusal(), 'a unit has no active switch to offer');

        Livewire::test(ManageTaxCodes::class)->callTableAction('delete', TaxCode::default());
        $this->assertStringContainsString('is used on Sales Invoices', $this->refusal());
        $this->assertNotNull(TaxCode::default());

        Livewire::test(ListItems::class)->callTableAction('delete', $item->fresh());
        $this->assertNull($item->fresh(), 'an item nothing uses is deleted');
    }

    public function test_a_parent_is_refused_while_it_has_children(): void
    {
        $sales = $this->sampleDepartment();
        $this->sampleDepartment(['code' => 'D-N', 'name' => 'Sales north', 'parent_id' => $sales->id]);

        Livewire::test(ManageDepartments::class)->callTableAction('delete', $sales);
        $this->assertStringStartsWith('D-SALES Sales is used on Departments (under it), so it cannot be deleted.', $this->refusal());
        $this->assertNotNull(Department::query()->find($sales->id));
    }

    public function test_a_document_another_points_at_is_refused_in_words_not_sql(): void
    {
        $invoice = $this->invoice($this->sampleCustomer(), $this->sampleItem(['item_type' => 'service']));
        $exchange = InvoiceExchange::query()->create(['number' => 'IE-1', 'trans_date' => '2026-11-12', 'customer_id' => $invoice->customer_id, 'collect_date' => '2026-11-20', 'due_date' => '2026-12-10', 'created_by' => auth()->id()]);
        $exchange->lines()->create(['sales_invoice_id' => $invoice->id]);

        Livewire::test(EditSalesInvoice::class, ['record' => $invoice->getRouteKey()])->callAction('delete')->assertNoRedirect();
        $this->assertStringStartsWith('INV-1 is used on Invoice Exchanges, so it cannot be deleted.', $this->refusal());
        $this->assertNotNull($invoice->fresh());
        $this->assertNotNull($invoice->fresh()->posting, 'the posting is untouched');
    }

    public function test_only_a_reference_refusal_is_translated(): void
    {
        $customer = $this->sampleCustomer();
        $this->expectException(QueryException::class);
        RecordInUse::guard($customer, fn () => DB::statement('SELECT * FROM no_such_table'));
    }
}
