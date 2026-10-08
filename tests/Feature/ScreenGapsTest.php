<?php

namespace Tests\Feature;

use App\Domain\Posting\DocumentRepository;
use App\Domain\Tax\TaxFilingService;
use App\Filament\Pages\Company\Calendar;
use App\Filament\Pages\Reports\VatReturn;
use App\Filament\Pages\Tax\ETaxInvoiceExport;
use App\Filament\Resources\CashBank\CashReceipts\Pages\ListCashReceipts;
use App\Filament\Resources\Company\Employees\Pages\ListEmployees;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\ListInventoryAdjustments;
use App\Filament\Resources\Inventory\Items\Pages\ListItems;
use App\Filament\Resources\Inventory\ItemTransfers\Pages\ListItemTransfers;
use App\Filament\Resources\Sales\SalesDownPayments\Pages\ListSalesDownPayments;
use App\Models\Company\CalendarEvent;
use App\Models\Company\TaxCode;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use App\Models\Tax\VatReturnRecord;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** The small gaps on existing screens: e-Tax filters and columns, a saved VAT return, calendar views, list filters and columns. */
class ScreenGapsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();
    }

    private function invoice(string $number, array $customer): SalesInvoice
    {
        $buyer = $this->sampleCustomer($customer);
        $item = $this->sampleItem(['number' => 'SVC-'.$number, 'name' => 'Service '.$number, 'item_type' => 'service']);
        $invoice = SalesInvoice::query()->create(['number' => $number, 'trans_date' => '2026-11-05', 'customer_id' => $buyer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 1, 'unit_id' => $item->unit1_id, 'base_quantity' => 1, 'unit_price' => 1_000_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);

        return $invoice->fresh();
    }

    public function test_the_e_tax_screen_filters_by_document_kind_and_status_and_explains_each_invoice(): void
    {
        $registered = $this->invoice('INV-A', ['wp_type' => 'npwp', 'wp_number' => '09.876.543.2-109.000']);
        $walkIn = $this->invoice('INV-B', ['number' => 'C-00002', 'name' => 'Walk-in buyer']);

        Livewire::test(ETaxInvoiceExport::class)
            ->assertCanSeeTableRecords([$registered, $walkIn])
            ->assertSee('No buyer tax ID: reported in the aggregate.')
            ->set('filters.document', 'aggregated')
            ->assertCanSeeTableRecords([$walkIn])
            ->assertCanNotSeeTableRecords([$registered]);

        $service = app(TaxFilingService::class);
        $service->export(SalesInvoice::query()->whereKey($registered->id)->get(), 'coretax', 2026, 11);
        $service->export(SalesInvoice::query()->whereKey($registered->id)->get(), 'coretax', 2026, 11);
        Livewire::test(ETaxInvoiceExport::class)
            ->set('filters.status', 'exported')
            ->assertCanSeeTableRecords([$registered])
            ->assertCanNotSeeTableRecords([$walkIn])
            ->assertSee('Replacement');

        $registered->forceFill(['nsfp' => '04002600000123'])->saveQuietly();
        Livewire::test(ETaxInvoiceExport::class)
            ->set('filters.status', 'numbered')
            ->assertCanSeeTableRecords([$registered])
            ->set('filters.status', 'draft')
            ->assertCanSeeTableRecords([$walkIn])
            ->assertCanNotSeeTableRecords([$registered]);
    }

    public function test_a_vat_return_is_saved_as_a_numbered_record(): void
    {
        $this->invoice('INV-A', ['wp_type' => 'npwp', 'wp_number' => '09.876.543.2-109.000']);

        Livewire::test(VatReturn::class)
            ->set('filters.from', '2026-11-01')
            ->set('filters.until', '2026-11-30')
            ->callAction('saveReturn', ['notes' => 'November'])
            ->assertHasNoActionErrors();

        $return = VatReturnRecord::query()->firstOrFail();
        $this->assertNotSame('', $return->number);
        $this->assertSame(110_000, $return->vat_out);
        $this->assertSame(110_000, $return->payable);
        $this->assertSame('2026-11-30', $return->until_date->toDateString());
        $this->assertStringContainsString($return->number, view('filament.pages.reports.vat-returns-saved', ['returns' => VatReturnRecord::query()->get()])->render());
    }

    public function test_the_calendar_shows_a_week_and_an_agenda(): void
    {
        CalendarEvent::query()->create(['title' => 'Stock count', 'starts_on' => '2026-11-18', 'created_by' => auth()->id()]);
        CalendarEvent::query()->create(['title' => 'Year-end party', 'starts_on' => '2026-12-12', 'created_by' => auth()->id()]);

        Livewire::test(Calendar::class)
            ->call('show', 'week')
            ->assertSee('16 Nov 2026 – 22 Nov 2026')
            ->assertSee('Stock count')
            ->assertDontSee('Year-end party')
            ->call('nextMonth')
            ->assertSee('23 Nov 2026')
            ->call('show', 'agenda')
            ->assertSee('The next 30 days')
            ->assertSee('Stock count')
            ->assertSee('Year-end party');
    }

    public function test_the_lists_gain_their_missing_filters_and_columns(): void
    {
        Livewire::test(ListSalesDownPayments::class)->assertTableFilterExists('is_printed');
        Livewire::test(ListCashReceipts::class)->assertTableFilterExists('cheque_date');
        Livewire::test(ListItemTransfers::class)->assertTableFilterExists('warehouse_id')->assertTableFilterExists('reference_warehouse_id');
        Livewire::test(ListItems::class)->assertTableColumnExists('my_stock');
        Livewire::test(ListEmployees::class)->assertTableColumnExists('open_payroll');
        Livewire::test(ListInventoryAdjustments::class)->assertTableColumnExists('lines_sum_total_cost');
    }
}
