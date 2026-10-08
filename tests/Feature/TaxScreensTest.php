<?php

namespace Tests\Feature;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Filament\Pages\Tax\ETaxInvoiceExport;
use App\Filament\Pages\Tax\LegacyETaxExport;
use App\Models\Company\TaxCode;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use App\Models\Tax\TaxFiling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TaxScreensTest extends TestCase
{
    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        Storage::fake('local');
        $this->seed();
        $this->actingAsAdmin();
        app(Preferensi::class)->setMany([PreferensiKey::CompanyNpwp->value => '01.234.567.8-901.000']);
        $customer = $this->sampleCustomer(['wp_type' => 'npwp', 'wp_number' => '09.876.543.2-109.000']);
        $item = $this->sampleItem();
        $docs = app(DocumentRepository::class);
        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-10-01', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $docs->created($opening);
        $this->invoice = SalesInvoice::query()->create(['number' => 'INV-2611-0001', 'trans_date' => '2026-11-05', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $this->invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 10, 'unit_id' => $item->unit1_id, 'base_quantity' => 10, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $this->invoice->refreshTotal();
        $docs->created($this->invoice);
    }

    public function test_the_export_screen_lists_the_period_exports_a_selection_and_takes_serials_back(): void
    {
        $page = Livewire::test(ETaxInvoiceExport::class)
            ->set('filters.month', 11)
            ->set('filters.year', 2026)
            ->assertCanSeeTableRecords([$this->invoice])
            ->assertSee('Draft');

        $page->callTableBulkAction('export', [$this->invoice])->assertHasNoTableBulkActionErrors();
        $filing = TaxFiling::query()->firstOrFail();
        $this->assertSame(TaxFiling::CORETAX, $filing->format);
        $this->assertSame(1, $filing->document_count);
        Storage::disk('local')->assertExists($filing->file_path);

        $page->callAction('pasteSerials', ['pasted' => 'INV-2611-0001 010.002-26.00000777'])
            ->assertHasNoActionErrors()
            ->assertNotified('1 serial(s) stored');
        $this->assertSame('010.002-26.00000777', $this->invoice->fresh()->nsfp);

        Livewire::test(ETaxInvoiceExport::class)->set('filters.month', 10)->set('filters.year', 2026)->assertCanNotSeeTableRecords([$this->invoice]);

        Livewire::test(LegacyETaxExport::class)
            ->set('filters.month', 11)->set('filters.year', 2026)
            ->assertCanSeeTableRecords([$this->invoice])
            ->callTableBulkAction('export', [$this->invoice])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame(TaxFiling::LEGACY, TaxFiling::query()->latest('id')->first()->format);
    }
}
