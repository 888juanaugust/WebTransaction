<?php

namespace Tests\Feature\Domain;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\Period;
use App\Domain\Sales\OrderApproval;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Models\CashBank\BankTransfer;
use App\Models\CashBank\Giro;
use App\Models\Company\Department;
use App\Models\Company\OpeningBalance;
use App\Models\FixedAssets\AssetChange;
use App\Models\FixedAssets\AssetDepreciation;
use App\Models\FixedAssets\AssetDisposal;
use App\Models\FixedAssets\FixedAsset;
use App\Models\GeneralLedger\AccountOpeningBalance;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemTransfer;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\VendorClaim;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesQuotation;
use App\Models\Sales\SalesReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/** Priced documents tag their lines and charges; revenue and cost of sales read per department; pulled lines keep their tags. */
class PricedDocumentTagsTest extends TestCase
{
    private Department $retail;

    private Department $projects;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        app(Preferensi::class)->setMany([PreferensiKey::Department->value => true, PreferensiKey::Project->value => true]);
        $this->freshRequest();
        $this->retail = $this->sampleDepartment(['code' => 'D-RTL', 'name' => 'Retail']);
        $this->projects = $this->sampleDepartment(['code' => 'D-PRJ', 'name' => 'Project sales']);
        $this->item = $this->sampleItem();

        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-1', 'trans_date' => '2026-10-01', 'created_by' => auth()->id()]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        app(DocumentRepository::class)->created($adjustment);
    }

    /** @return array<string, int> the income statement's totals by row id */
    private function totals(?int $department = null): array
    {
        return collect(FinancialStatements::incomeStatement(new Period('2026-11-01', '2026-11-30', null, $department)))
            ->filter(fn (array $row) => $row['is_total'])->mapWithKeys(fn (array $row) => [$row['id'] => $row['amount']])->all();
    }

    public function test_a_two_department_invoice_splits_revenue_and_cost_of_sales(): void
    {
        $customer = $this->sampleCustomer();
        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'taxable' => false, 'inclusive_tax' => false, 'department_id' => $this->retail->id, 'created_by' => auth()->id()]);
        $line = ['item_id' => $this->item->id, 'unit_id' => $this->item->unit1_id, 'unit_price' => 150_000, 'warehouse_id' => Warehouse::default()->id];
        $invoice->lines()->createMany([
            $line + ['sort' => 0, 'quantity' => 2, 'base_quantity' => 2],
            $line + ['sort' => 1, 'quantity' => 3, 'base_quantity' => 3, 'department_id' => $this->projects->id],
        ]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);

        $this->assertSame(['t-revenue' => 750_000, 't-cost_of_sales' => 500_000], array_intersect_key($this->totals(), ['t-revenue' => 0, 't-cost_of_sales' => 0]));
        $retail = $this->totals($this->retail->id);
        $this->assertSame([300_000, 200_000, 100_000], [$retail['t-revenue'], $retail['t-cost_of_sales'], $retail['t-gross']], 'the untagged line takes the header\'s department');
        $projects = $this->totals($this->projects->id);
        $this->assertSame([450_000, 300_000, 150_000], [$projects['t-revenue'], $projects['t-cost_of_sales'], $projects['t-gross']]);
    }

    public function test_an_invoice_made_from_an_order_keeps_the_order_header_and_line_tags(): void
    {
        $project = $this->sampleProject();
        $customer = $this->sampleCustomer();
        $order = SalesOrder::query()->create(['number' => 'SO-1', 'trans_date' => '2026-11-12', 'customer_id' => $customer->id, 'taxable' => false, 'inclusive_tax' => false,
            'department_id' => $this->retail->id, 'project_id' => $project->id, 'approval_status' => app(OrderApproval::class)->initialStatus(), 'created_by' => auth()->id()]);
        $order->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 4, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 4, 'unit_price' => 150_000, 'warehouse_id' => Warehouse::default()->id, 'department_id' => $this->projects->id]);
        $order->refreshTotal();
        app(DocumentRepository::class)->created($order);

        Livewire::withQueryParams(['source' => 'order:'.$order->id])->test(CreateSalesInvoice::class)
            ->assertFormFieldExists('department_id')
            ->assertSet('data.department_id', $this->retail->id)
            ->assertSet('data.project_id', $project->id)
            ->assertSet('data.lines', fn (array $lines) => (int) reset($lines)['department_id'] === $this->projects->id && reset($lines)['project_id'] === null);
    }

    public function test_every_posting_document_and_its_priced_lines_and_charges_carry_the_tags(): void
    {
        // Documents that book nothing to income or expense, or are outside this release's tags.
        $untagged = [
            BankTransfer::class => 'moves money between cash accounts',
            Giro::class => 'clears or bounces a giro already booked',
            ItemTransfer::class => 'moves stock between warehouses',
            AccountOpeningBalance::class => 'opening balances',
            OpeningBalance::class => 'opening balances, posted against equity on the data start date',
            FixedAsset::class => 'fixed assets are not tagged in this release',
            AssetChange::class => 'fixed assets are not tagged in this release',
            AssetDepreciation::class => 'fixed assets are not tagged in this release',
            AssetDisposal::class => 'fixed assets are not tagged in this release',
        ];
        // Their lines settle invoices; the header's tags apply.
        $allocationLines = [SalesReceipt::class, PurchasePayment::class];

        $postables = collect(glob(app_path('Models/*/*.php')))
            ->map(fn (string $file) => 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path('Models/')))))
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Postable::class))
            ->reject(fn (string $class) => isset($untagged[$class]))
            ->merge([SalesQuotation::class, SalesOrder::class, PurchaseOrder::class, VendorClaim::class]); // upstream documents a posting one pulls from
        $this->assertGreaterThanOrEqual(20, $postables->count());

        foreach ($postables as $class) {
            /** @var Model $document */
            $document = new $class;
            $tables = [$document->getTable()];
            foreach (['lines', 'charges'] as $relation) {
                if (method_exists($document, $relation) && ! in_array($class, $allocationLines, true)) {
                    $tables[] = $document->{$relation}()->getRelated()->getTable();
                }
            }
            foreach ($tables as $table) {
                $this->assertTrue(Schema::hasColumns($table, ['department_id', 'project_id']), "{$table} ({$class}) carries no department and project");
            }
        }
    }
}
