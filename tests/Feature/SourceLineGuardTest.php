<?php

namespace Tests\Feature;

use App\Domain\Posting\DocumentRepository;
use App\Filament\Resources\Sales\Deliveries\Pages\CreateDelivery;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Models\Company\TaxCode;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseRequisition;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** A line's link to an upstream line, sent by the browser, is checked: its kind, the same customer, item and unit, and the upstream price. */
class SourceLineGuardTest extends TestCase
{
    public function test_a_line_cannot_point_at_another_customers_order(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $mine = $this->sampleCustomer();
        $theirs = $this->sampleCustomer(['number' => 'C-00002', 'name' => 'Other Co']);
        $item = $this->sampleItem();
        $order = SalesOrder::query()->create(['number' => 'SO-THEIRS', 'trans_date' => '2026-11-10', 'customer_id' => $theirs->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $orderLine = $order->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => 5, 'unit_id' => $item->unit1_id, 'base_quantity' => 5, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]);

        Livewire::test(CreateDelivery::class)
            ->fillForm(['customer_id' => $mine->id, 'trans_date' => '2026-11-16'])
            ->set('data.lines', ['a' => ['item_id' => $item->id, 'quantity' => 5, 'unit_id' => $item->unit1_id, 'unit_price' => 150_000, 'discount_percent' => 0, 'discount_amount' => 0,
                'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id, 'source_line_type' => 'sales_order_line', 'source_line_id' => $orderLine->id]])
            ->call('create')
            ->assertHasErrors('data.lines');

        $this->assertSame(0, Delivery::query()->count());
        $this->assertSame('0.0000', $orderLine->fresh()->processed_quantity, 'the other customer\'s order is untouched');
    }

    public function test_a_pulled_line_keeps_its_item_and_unit_and_comes_from_the_document_before(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $customer = $this->sampleCustomer();
        $cheap = $this->sampleItem(['item_type' => 'service']); // no stock to issue
        $dear = $this->sampleItem(['number' => 'ITM-DEAR', 'name' => 'Dear thing', 'sell_price' => 9_000_000, 'item_type' => 'service']);
        $order = SalesOrder::query()->create(['number' => 'SO-1', 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $orderLine = $order->lines()->create(['sort' => 0, 'item_id' => $cheap->id, 'quantity' => 5, 'unit_id' => $cheap->unit1_id, 'base_quantity' => 5, 'unit_price' => 1_000, 'warehouse_id' => Warehouse::default()->id]);
        app(DocumentRepository::class)->created($order);
        $requisition = PurchaseRequisition::query()->create(['number' => 'PR-1', 'trans_date' => '2026-11-10', 'created_by' => auth()->id()]);
        $requisitionLine = $requisition->lines()->create(['sort' => 0, 'item_id' => $dear->id, 'quantity' => 5, 'unit_id' => $dear->unit1_id, 'base_quantity' => 5]);

        $invoice = fn (int $itemId, string $type, int $sourceId) => Livewire::test(CreateSalesInvoice::class)
            ->fillForm(['customer_id' => $customer->id, 'trans_date' => '2026-11-16'])
            ->set('data.lines', ['a' => ['item_id' => $itemId, 'quantity' => 5, 'unit_id' => $dear->unit1_id, 'unit_price' => 1_000, 'discount_percent' => 0, 'discount_amount' => 0,
                'warehouse_id' => Warehouse::default()->id, 'source_line_type' => $type, 'source_line_id' => $sourceId]])
            ->call('create');

        // The order's cheap line, with the dear item put on it: the order's price would sell the dear item.
        $invoice($dear->id, 'sales_order_line', $orderLine->id)->assertHasErrors('data.lines');
        // A requisition line has no price to keep, and an invoice never pulls from a requisition.
        $invoice($dear->id, 'purchase_requisition_line', $requisitionLine->id)->assertHasErrors('data.lines');
        $this->assertSame(0, SalesInvoice::query()->count());

        $invoice($cheap->id, 'sales_order_line', $orderLine->id)->assertHasNoErrors();
        $this->assertSame(1, SalesInvoice::query()->count(), 'the order\'s own line, as it is');
    }
}
