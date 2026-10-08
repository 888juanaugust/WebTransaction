<?php

namespace Tests\Feature\Domain;

use App\Domain\Posting\DocumentRepository;
use App\Domain\Sales\CommissionCalculator;
use App\Domain\Sales\SalesTargetProgress;
use App\Filament\Resources\Sales\SalesmanCommissions\Pages\CommissionStatement;
use App\Filament\Resources\Sales\SalesTargets\Pages\EditSalesTarget;
use App\Models\Company\Employee;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesmanCommission;
use App\Models\Sales\SalesReceipt;
use App\Models\Sales\SalesReturn;
use App\Models\Sales\SalesTarget;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Commissions are calculated from the rules on invoiced or paid sales, and targets show how far they have come. */
class CommissionTest extends TestCase
{
    private DocumentRepository $docs;

    private Customer $customer;

    private Item $item;

    private Employee $ana;

    private Employee $ben;

    private SalesInvoice $first;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-30 10:00:00'));
        $this->seed();
        $this->enableAllModules();
        $this->actingAsAdmin();
        $this->docs = app(DocumentRepository::class);
        $this->customer = $this->sampleCustomer();
        $this->item = $this->sampleItem()->fresh();
        $this->ana = $this->sampleEmployee(['name' => 'Ana']);
        $this->ben = $this->sampleEmployee(['number' => 'EMP-00002', 'name' => 'Ben']);

        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-10-01', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        $this->docs->created($opening);

        // Ana sells four and takes one back; Ben sells two. Nothing is taxed, so totals are net.
        $this->first = $this->invoice($this->ana, 4, '2026-11-03');
        $this->invoice($this->ben, 2, '2026-11-05');
        $return = SalesReturn::query()->create(['number' => 'SR-1', 'trans_date' => '2026-11-08', 'customer_id' => $this->customer->id, 'return_type' => 'invoice', 'source_type' => 'sales_invoice', 'source_id' => $this->first->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $return->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 1, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 1, 'unit_price' => 150_000, 'warehouse_id' => Warehouse::default()->id, 'salesman_id' => $this->ana->id]);
        $return->refreshTotal();
        $this->docs->created($return);

        $rule = fn (array $a) => SalesmanCommission::query()->create($a + ['active_period' => 'forever', 'salesman_scope' => 'all', 'requirement' => 'none', 'gain_type' => 'percent', 'gain_basis' => 'sales_value', 'is_active' => true]);
        $rule(['name' => 'Five percent', 'gain_value' => 5]);
        $rule(['name' => 'Ana bonus', 'salesman_scope' => 'specific', 'requirement' => 'sales_value', 'requirement_from' => 400_000, 'requirement_to' => 0, 'gain_type' => 'fixed', 'gain_amount' => 50_000])->salesmen()->attach($this->ana);
        $rule(['name' => 'Per pair', 'requirement' => 'per_qty', 'requirement_qty' => 2, 'gain_type' => 'fixed', 'gain_amount' => 10_000]);
        $rule(['name' => 'Off', 'gain_value' => 50, 'is_active' => false]);
        $rule(['name' => 'October only', 'gain_value' => 50, 'active_period' => 'period', 'from_date' => '2026-10-01', 'to_date' => '2026-10-31']);
    }

    private function invoice(Employee $salesman, int $qty, string $date): SalesInvoice
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-'.uniqid(), 'trans_date' => $date, 'customer_id' => $this->customer->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => 150_000, 'warehouse_id' => Warehouse::default()->id, 'salesman_id' => $salesman->id]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        return $invoice->fresh();
    }

    public function test_on_the_invoiced_basis_each_rule_that_applies_adds_its_gain(): void
    {
        $rows = collect(app(CommissionCalculator::class)->statement('2026-11-01', '2026-11-30', 'invoice'))->keyBy('name');

        $this->assertSame(450_000, $rows['Ana']['sales'], 'four sold, one returned');
        $this->assertSame('3.0000', $rows['Ana']['quantity']);
        $this->assertSame(150_000, $rows['Ana']['profit'], 'at the cost the goods left with');
        $this->assertSame(['Ana bonus' => 50_000, 'Five percent' => 22_500, 'Per pair' => 10_000], collect($rows['Ana']['rules'])->pluck('amount', 'name')->all());
        $this->assertSame(82_500, $rows['Ana']['commission']);
        $this->assertSame(15_000 + 10_000, $rows['Ben']['commission'], 'no bonus for Ben; the inactive and the October rules apply to nobody');
    }

    public function test_on_the_paid_basis_only_what_customers_paid_in_the_period_counts(): void
    {
        $receipt = SalesReceipt::query()->create(['number' => 'CB-1', 'trans_date' => '2026-11-20', 'customer_id' => $this->customer->id, 'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $this->first->id, 'amount' => 300_000, 'discount' => 0]);
        $receipt->refreshTotal();
        $this->docs->created($receipt);

        $rows = collect(app(CommissionCalculator::class)->statement('2026-11-01', '2026-11-30', 'payment'))->keyBy('name');
        $this->assertSame(['Ana'], $rows->keys()->all(), 'Ben\'s invoice is unpaid; the return was not used as credit');
        $this->assertSame(300_000, $rows['Ana']['sales'], 'half of the first invoice');
        $this->assertSame(15_000 + 10_000, $rows['Ana']['commission'], 'below the bonus threshold');
        $this->assertSame([], app(CommissionCalculator::class)->statement('2026-12-01', '2026-12-31', 'payment'));

        Livewire::test(CommissionStatement::class)
            ->assertSee('Ana')
            ->assertSee('Per pair 10.000')
            ->set('filters.basis', 'invoice')
            ->assertSee('82.500');
    }

    public function test_a_target_shows_what_was_sold_against_it(): void
    {
        $target = SalesTarget::query()->create(['name' => 'November', 'target_type' => 'per_salesman', 'from_date' => '2026-11-01', 'to_date' => '2026-11-30']);
        $target->lines()->createMany([
            ['sort' => 0, 'salesman_id' => $this->ana->id, 'value' => 1_000_000, 'quantity' => 0],
            ['sort' => 1, 'salesman_id' => $this->ben->id, 'value' => 0, 'quantity' => 4],
        ]);
        $rows = collect(SalesTargetProgress::of($target))->keyBy('label');
        $this->assertSame(450_000, $rows['Ana']['value']);
        $this->assertSame(45, $rows['Ana']['percent']);
        $this->assertSame(50, $rows['Ben']['percent'], 'two of four');

        $monthly = SalesTarget::query()->create(['name' => 'By month', 'target_type' => 'per_month', 'from_date' => '2026-01-01', 'to_date' => '2026-12-31']);
        $monthly->lines()->create(['sort' => 0, 'month' => 11, 'value' => 900_000, 'quantity' => 0]);
        $this->assertSame(750_000, SalesTargetProgress::of($monthly)[0]['value']);

        Livewire::test(EditSalesTarget::class, ['record' => $target->getRouteKey()])->assertSee('45 %');
    }
}
