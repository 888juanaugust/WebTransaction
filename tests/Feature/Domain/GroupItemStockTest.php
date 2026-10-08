<?php

namespace Tests\Feature\Domain;

use App\Domain\Inventory\Exceptions\NegativeStockException;
use App\Domain\Inventory\GroupItems;
use App\Domain\Inventory\StockQuery;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Enums\ItemType;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReturn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A group item (bundle) sold moves its stocked components: stock, cost and the component's own accounts. */
class GroupItemStockTest extends TestCase
{
    private Customer $customer;

    private Item $widget;

    private Item $gadget;

    private Item $kit;

    private Warehouse $warehouse;

    private DocumentRepository $docs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->customer = $this->sampleCustomer();
        $this->warehouse = Warehouse::default();
        $this->docs = app(DocumentRepository::class);
        $set = Unit::query()->firstOrCreate(['name' => 'SET'])->id;
        foreach ([['5110', 'Cost of Gadgets Sold'], ['5120', 'Cost of Kits Sold']] as [$no, $name]) {
            Account::query()->create(['no' => $no, 'name' => $name, 'account_type' => AccountType::CostOfSales]);
        }

        $this->widget = $this->sampleItem()->fresh();
        // The gadget is costed to its own cost of sales account and packed in sets of two.
        $this->gadget = $this->sampleItem(['number' => 'ITM-00002', 'name' => 'Gadget', 'cogs_account_id' => $this->account('5110')]);
        $this->gadget->units()->create(['sort' => 0, 'unit_id' => $set, 'ratio' => 2]);
        $service = $this->sampleItem(['number' => 'SVC-00001', 'name' => 'Fitting', 'item_type' => ItemType::Service]);

        $this->kit = $this->sampleItem(['number' => 'KIT-00001', 'name' => 'Starter kit', 'item_type' => ItemType::Group, 'sell_price' => 400_000, 'cogs_account_id' => $this->account('5120')]);
        $this->kit->components()->createMany([
            ['sort' => 0, 'item_id' => $this->widget->id, 'quantity' => 1, 'unit_id' => $this->widget->unit1_id],
            ['sort' => 1, 'item_id' => $this->gadget->id, 'quantity' => 1, 'unit_id' => $set],
            ['sort' => 2, 'item_id' => $service->id, 'quantity' => 1, 'unit_id' => $service->unit1_id],
        ]);

        $this->stockUp(['widget' => [20, 100_000], 'gadget' => [30, 40_000]], '2026-04-01');
    }

    /** @param  array<string, array{0: int, 1: int}>  $quantities  item property => [quantity, unit cost] */
    private function stockUp(array $quantities, string $date): void
    {
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-'.uniqid(), 'trans_date' => $date, 'created_by' => auth()->id()]);
        $sort = 0;
        foreach ($quantities as $property => [$quantity, $cost]) {
            $item = $this->{$property};
            $adjustment->lines()->create(['sort' => $sort++, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => $quantity, 'unit_id' => $item->unit1_id, 'base_quantity' => $quantity, 'unit_cost' => $cost, 'total_cost' => 0, 'warehouse_id' => $this->warehouse->id]);
        }
        $this->docs->created($adjustment);
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[$this->account($no)] ?? 0;
    }

    private function kitLine(int $quantity, array $extra = []): array
    {
        return array_merge(['sort' => 0, 'item_id' => $this->kit->id, 'quantity' => $quantity, 'unit_id' => $this->kit->unit1_id, 'base_quantity' => $quantity, 'unit_price' => 400_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => $this->warehouse->id], $extra);
    }

    private function deliver(int $kits): Delivery
    {
        $delivery = Delivery::query()->create(['number' => 'DO-'.uniqid(), 'trans_date' => '2026-11-03', 'customer_id' => $this->customer->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $delivery->lines()->create($this->kitLine($kits));
        $delivery->refreshTotal();
        $this->docs->created($delivery);

        return $delivery->fresh();
    }

    private function invoice(array $line): SalesInvoice
    {
        $invoice = SalesInvoice::query()->create(['number' => 'INV-'.uniqid(), 'trans_date' => '2026-11-05', 'customer_id' => $this->customer->id, 'taxable' => true, 'inclusive_tax' => false, 'payment_term_id' => $this->customer->payment_term_id, 'created_by' => auth()->id()]);
        $invoice->lines()->create($line);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        return $invoice->fresh();
    }

    public function test_a_group_explodes_into_its_stocked_components_in_base_units(): void
    {
        $pieces = collect(GroupItems::explode($this->kit, 3))->mapWithKeys(fn ($p) => [$p['item']->number => $p['base_quantity']])->all();

        $this->assertSame(['ITM-00001' => '3.0000', 'ITM-00002' => '6.0000'], $pieces, 'a set of gadgets is two pieces; the fitting service moves nothing');
        $this->assertSame([], GroupItems::explode(Item::query()->where('number', 'SVC-00001')->first(), 3));
        $this->assertSame('3.0000', GroupItems::explode($this->widget, 3)[0]['base_quantity']);
    }

    public function test_delivering_two_kits_moves_component_stock_and_the_invoice_books_each_components_cost_of_sales(): void
    {
        $delivery = $this->deliver(2);

        $this->assertSame('18.0000', StockQuery::onHand($this->widget->id));
        $this->assertSame('26.0000', StockQuery::onHand($this->gadget->id));
        $this->assertSame(2, StockMovement::query()->active()->where('source_line_type', 'delivery_line')->where('source_line_id', $delivery->lines()->first()->id)->count(), 'one movement per stocked component, both sourced to the kit line');
        $this->assertSame(0, StockMovement::query()->active()->where('item_id', $this->kit->id)->count(), 'the group itself keeps no stock');
        $this->assertSame(2 * 100_000 + 4 * 40_000, $this->balance('1310'), 'goods delivered, not invoiced, at the components\' cost');
        $this->assertSame(20 * 100_000 + 30 * 40_000 - 360_000, $this->balance('1300'));

        $this->invoice($this->kitLine(2, ['source_line_type' => 'delivery_line', 'source_line_id' => $delivery->lines()->first()->id]));

        $this->assertSame(800_000, $this->balance('4100'), 'revenue on the kit');
        $this->assertSame(200_000, $this->balance('5100'), 'the widget on the default cost of sales account');
        $this->assertSame(160_000, $this->balance('5110'), 'the gadget on its own account');
        $this->assertSame(0, $this->balance('5120'), 'the group\'s own account is never used for cost');
        $this->assertSame(0, $this->balance('1310'), 'in-transit cleared');
    }

    public function test_a_returned_kit_brings_its_components_back_at_the_cost_they_left_with(): void
    {
        $invoice = $this->invoice($this->kitLine(2));
        $this->assertSame('18.0000', StockQuery::onHand($this->widget->id), 'a direct invoice takes the components out');
        $this->assertSame('26.0000', StockQuery::onHand($this->gadget->id));
        $this->assertSame(360_000, $this->balance('5100') + $this->balance('5110'));

        // Gadgets bought dearer afterwards move the average, not the cost the returned ones left with.
        $this->stockUp(['gadget' => [10, 70_000]], '2026-11-06');

        $return = SalesReturn::query()->create(['number' => 'SR-1', 'trans_date' => '2026-11-08', 'customer_id' => $this->customer->id, 'return_type' => 'invoice', 'source_type' => 'sales_invoice', 'source_id' => $invoice->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $return->lines()->create($this->kitLine(1));
        $return->refreshTotal();
        $this->docs->created($return);

        $this->assertSame('19.0000', StockQuery::onHand($this->widget->id));
        $this->assertSame('38.0000', StockQuery::onHand($this->gadget->id));
        $this->assertSame(100_000, $this->balance('5100'));
        $this->assertSame(80_000, $this->balance('5110'), 'two gadgets back at 40,000, not at the new average');
    }

    public function test_a_component_short_of_stock_refuses_the_delivery(): void
    {
        $this->stockUp(['gadget' => [-27, 40_000]], '2026-04-02');

        $this->expectException(NegativeStockException::class);
        $this->deliver(2);
    }
}
