<?php

namespace Tests\Feature\Domain;

use App\Domain\Imports\MasterImporter;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Models\Company\AuditLog;
use App\Models\Company\PrintLayout;
use App\Models\Company\TaxCode;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemBrand;
use App\Models\Inventory\ItemCategory;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PrintingAndImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        app(Preferensi::class)->setMany([PreferensiKey::CompanyName->value => 'Example Co', PreferensiKey::CompanyAddress->value => 'Jl. Industri 9, Jakarta']);
    }

    public function test_an_invoice_prints_under_its_layout_is_marked_printed_and_needs_the_right(): void
    {
        $customer = $this->sampleCustomer(['bill_street' => 'Jl. Raya 1', 'bill_city' => 'Jakarta']);
        $item = $this->sampleItem();
        $docs = app(DocumentRepository::class);
        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-10-01', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $docs->created($opening);
        $invoice = SalesInvoice::query()->create(['number' => 'INV-2611-0001', 'trans_date' => '2026-11-05', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'description' => 'Thank you for your order', 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 10, 'unit_id' => $item->unit1_id, 'base_quantity' => 10, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        $docs->created($invoice);
        $this->assertFalse($invoice->fresh()->is_printed);

        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'sales_invoice', 'id' => $invoice->id]))
            ->assertOk()
            ->assertSee('Example Co')->assertSee('Jl. Industri 9')
            ->assertSee('Invoice')->assertSee('INV-2611-0001')->assertSee('Acme Trading')->assertSee('Jl. Raya 1')
            ->assertSee('Widget')->assertSee('ITM-00001')->assertSee('1.500.000')->assertSee('165.000')->assertSee('Rp 1.665.000')
            ->assertSee('Thank you for your order')->assertSee('Prepared by');
        $this->assertTrue($invoice->fresh()->is_printed);
        $this->assertSame(1, AuditLog::query()->where('action', 'printed')->count());

        // The layout decides what prints: no item codes, no signatures, a custom heading and footer.
        PrintLayout::query()->where('transaction_type', 'sales_invoice')->update(['settings' => ['show_item_code' => false, 'show_signature' => false, 'title' => 'TAX INVOICE / FAKTUR', 'footer' => 'Goods sold are not returnable.']]);
        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'sales_invoice', 'id' => $invoice->id]))
            ->assertOk()->assertSee('TAX INVOICE / FAKTUR')->assertSee('Goods sold are not returnable.')->assertDontSee('Prepared by')
            ->assertDontSee('<td class="mono">ITM-00001</td>', false);

        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'sales_invoice', 'id' => 999]))->assertNotFound();
        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'nothing', 'id' => $invoice->id]))->assertForbidden();

        $this->actingAs(User::factory()->create());
        $this->get(URL::signedRoute('filament.admin.print', ['alias' => 'sales_invoice', 'id' => $invoice->id]))->assertForbidden();
    }

    public function test_customers_and_items_import_from_the_template_and_bad_rows_are_reported_not_imported(): void
    {
        $template = MasterImporter::template('customers');
        $this->assertStringStartsWith('number,name,phone,email', $template);
        $csv = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($csv, implode("\n", [
            'number,name,phone,email,bill_city,wp_number,payment_term,credit_limit',
            ',Acme Trading,021-555,acme@example.test,Jakarta,01.234.567.8-901.000,Net 30,"25.000.000"',
            ',Northwind Workshop,,,Bekasi,,,',
            ',,021-1,,,,,',
            ',Broken Row,,,,,"Net 999",',
        ]));
        $result = app(MasterImporter::class)->import('customers', $csv);
        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('"name" is required', $result['errors'][0]);
        $this->assertStringContainsString('payment term "Net 999"', $result['errors'][1]);
        $maju = Customer::query()->where('name', 'Acme Trading')->firstOrFail();
        $this->assertSame('C-00001', $maju->number);
        $this->assertSame(25_000_000, $maju->credit_limit_amount);
        $this->assertTrue($maju->credit_limit_amount_enabled);
        $this->assertSame('npwp', $maju->wp_type instanceof \BackedEnum ? $maju->wp_type->value : $maju->wp_type);
        $this->assertSame('C-00002', Customer::query()->where('name', 'Northwind Workshop')->value('number'));

        // A row with the number updates instead of duplicating.
        file_put_contents($csv, "number,name,phone\nC-00001,Acme Trading (new name),021-777\n");
        $again = app(MasterImporter::class)->import('customers', $csv);
        $this->assertSame(['created' => 0, 'updated' => 1, 'errors' => []], $again);
        $this->assertSame('Acme Trading (new name)', $maju->fresh()->name);
        $this->assertSame(2, Customer::query()->count());

        ItemBrand::query()->create(['name' => 'Alpha']);
        ItemCategory::query()->create(['name' => 'Spare Parts']);
        file_put_contents($csv, implode("\n", [
            'number,name,item_type,category,brand,unit,sell_price,purchase_price,min_stock',
            ',Widget Alpha 1234,inventory,Spare Parts,Alpha,PCS,150000,100000,10',
            ',Oil filter,inventory,,,CRATE,50000,30000,',
        ]));
        $items = app(MasterImporter::class)->import('items', $csv);
        $this->assertSame(1, $items['created']);
        $this->assertStringContainsString('Unit "CRATE"', $items['errors'][0]);
        $pad = Item::query()->where('name', 'Widget Alpha 1234')->firstOrFail();
        $this->assertSame('ITM-00001', $pad->number);
        $this->assertSame(150_000, $pad->sell_price);
        $this->assertSame('Alpha', $pad->brand?->name);
        $this->assertSame('Spare Parts', $pad->category?->name);

        file_put_contents($csv, "number,name\n,Contoso Supplies\n");
        $this->assertSame(1, app(MasterImporter::class)->import('vendors', $csv)['created']);
        $this->assertSame('V-00001', Vendor::query()->value('number'));

        file_put_contents($csv, "phone\n021\n");
        $this->expectException(\RuntimeException::class);
        app(MasterImporter::class)->import('vendors', $csv);
    }
}
