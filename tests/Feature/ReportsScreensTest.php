<?php

namespace Tests\Feature;

use App\Domain\Posting\DocumentRepository;
use App\Filament\Pages\Reports\BalanceSheet;
use App\Filament\Pages\Reports\ReportCatalogue;
use App\Filament\Pages\Reports\ReportRegistry;
use App\Filament\Pages\Reports\SalesByCustomer;
use App\Filament\Pages\Reports\StockCard;
use App\Filament\Pages\Reports\VatReturn;
use App\Models\CashBank\CashReceipt;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsScreensTest extends TestCase
{
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 09:00:00');
        CarbonImmutable::setTestNow('2026-11-30 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $docs = app(DocumentRepository::class);
        $acc = fn (string $no) => (int) Account::query()->where('no', $no)->value('id');
        $receipt = CashReceipt::query()->create(['number' => 'CR-1', 'trans_date' => '2026-11-01', 'bank_account_id' => $acc('1102'), 'payer' => 'Owner', 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'account_id' => $acc('3100'), 'amount' => 50_000_000]);
        $receipt->refreshTotal();
        $docs->created($receipt);
        $customer = $this->sampleCustomer();
        $this->item = $this->sampleItem();
        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-11-02', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $docs->created($opening);
        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-05', 'customer_id' => $customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        $docs->created($invoice);
    }

    public function test_every_report_in_the_catalogue_opens_and_exports(): void
    {
        $this->assertGreaterThanOrEqual(16, ReportRegistry::all()->count());
        foreach (ReportRegistry::all() as $class) {
            $this->get($class::getUrl())->assertOk()->assertSee($class::title());
            $response = Livewire::test($class)->callAction('export')->assertHasNoActionErrors();
            $this->assertNotNull($response, $class);
        }
        Livewire::test(ReportCatalogue::class)->assertSee('Balance Sheet')->assertSee('Stock Card')
            ->set('search', 'aging')->assertSee('Receivable Aging')->assertDontSee('Balance Sheet');
    }

    public function test_the_reports_show_the_figures_of_the_period(): void
    {
        Livewire::test(BalanceSheet::class)
            ->set('filters.from', '2026-11-01')->set('filters.until', '2026-11-30')
            ->assertSee('Total assets')->assertSee('52.665.000')->assertSee('52.500.000');

        Livewire::test(SalesByCustomer::class)
            ->set('filters.from', '2026-11-01')->set('filters.until', '2026-11-30')
            ->assertSee('Acme Trading')->assertSee('1.500.000')->assertSee('165.000');

        Livewire::test(StockCard::class)
            ->set('filters.from', '2026-11-01')->set('filters.until', '2026-11-30')
            ->set('filters.item_id', $this->item->id)
            ->assertSee('Opening balance')->assertSee('INV-1')->assertSee('1.000.000');

        Livewire::test(VatReturn::class)
            ->set('filters.from', '2026-11-01')->set('filters.until', '2026-11-30')
            ->assertSee('INV-1')->assertSee('1.375.000')->assertSee('165.000')->assertSee('Total VAT out');
    }
}
