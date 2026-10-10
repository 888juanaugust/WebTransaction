<?php

namespace Tests\Feature\Client\Support;

use App\Client\Access\CentralGroups;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Models\Company\Branch;
use App\Models\Company\TaxCode;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The company Central's order tests work in: two branches with a warehouse
 * each, the Sales Order Approval rule on, a customer at home in Jakarta with
 * a marketing seat, an item, and helpers to stock, order and deliver.
 */
trait OrderFlow
{
    protected User $owner;

    protected User $marketing;

    protected User $sales;

    protected User $finance;

    protected User $inventory;

    protected Customer $customer;

    protected Item $item;

    protected Branch $jakarta;

    protected Branch $surabaya;

    protected Warehouse $gudangJakarta;

    protected Warehouse $gudangSurabaya;

    protected TaxCode $vat;

    protected DocumentRepository $docs;

    protected function prepareOrderFlow(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        CarbonImmutable::setTestNow('2026-10-17 09:00:00');
        $this->seed();
        $this->owner = $this->actingAsAdmin();
        app(Preferensi::class)->set(PreferensiKey::SalesOrderApproval, true);

        $this->jakarta = Branch::default();
        $this->jakarta->forceFill(['code' => 'JKT', 'used_all_user' => false])->save();
        $this->surabaya = Branch::query()->create(['name' => 'Surabaya', 'code' => 'SBY', 'used_all_user' => false, 'is_active' => true]);
        $this->gudangJakarta = Warehouse::default();
        $this->gudangJakarta->forceFill(['name' => 'Gudang Jakarta', 'branch_id' => $this->jakarta->id])->save();
        $this->gudangSurabaya = Warehouse::query()->create(['name' => 'Gudang Surabaya', 'branch_id' => $this->surabaya->id, 'is_active' => true, 'used_all_user' => true]);

        $this->marketing = $this->member(CentralGroups::MARKETING, [$this->jakarta, $this->surabaya]);
        $this->sales = $this->member(CentralGroups::SALES, [$this->jakarta]);
        $this->finance = $this->member(CentralGroups::FINANCE, [$this->jakarta, $this->surabaya]);
        $this->inventory = $this->member(CentralGroups::PURCHASING, [$this->jakarta, $this->surabaya]);
        $this->customer = $this->sampleCustomer(['branch_id' => $this->jakarta->id, 'default_warehouse_id' => $this->gudangJakarta->id, 'sales_user_id' => $this->sales->id, 'marketing_user_id' => $this->marketing->id]);
        $this->item = $this->sampleItem();
        $this->vat = TaxCode::default();
        $this->docs = app(DocumentRepository::class);
    }

    /** @param  list<Branch>  $branches */
    protected function member(string $group, array $branches = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        CentralGroups::claim($group)->users()->attach($user);
        $user->branches()->sync(collect($branches)->map(fn (Branch $b) => $b->id)->all());

        return $user;
    }

    /** Puts goods on the shelf through a posted adjustment. */
    protected function stock(Warehouse $warehouse, int $qty, ?Item $item = null, string $date = '2026-10-01', int $cost = 100_000): InventoryAdjustment
    {
        $item ??= $this->item;
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-'.uniqid(), 'trans_date' => $date, 'created_by' => $this->owner->id]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $item->id, 'adjustment_type' => 'quantity', 'quantity' => $qty, 'unit_id' => $item->unit1_id, 'base_quantity' => $qty, 'unit_cost' => $cost, 'total_cost' => 0, 'warehouse_id' => $warehouse->id]);
        $this->docs->created($adjustment);

        return $adjustment;
    }

    /** An order entered by the sales, awaiting approval. */
    protected function order(int $qty, ?Warehouse $warehouse = null, int $price = 150_000, ?Item $item = null, ?User $enteredBy = null): SalesOrder
    {
        $item ??= $this->item;
        $enteredBy ??= $this->sales;
        $order = SalesOrder::query()->create(['number' => 'SO-'.uniqid(), 'trans_date' => today()->toDateString(), 'customer_id' => $this->customer->id, 'branch_id' => $this->customer->branch_id, 'taxable' => true, 'inclusive_tax' => false,
            'payment_term_id' => $this->customer->payment_term_id, 'approval_status' => SalesOrder::AWAITING, 'created_by' => $enteredBy->id]);
        $order->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => $qty, 'unit_id' => $item->unit1_id, 'base_quantity' => $qty, 'unit_price' => $price, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $warehouse?->id]);
        $order->refreshTotal();
        $this->docs->created($order);

        return $order->fresh();
    }

    /** A delivery pulled from the order's first line, from the given warehouse (the line's by default). */
    protected function deliver(SalesOrder $order, int $qty, ?Warehouse $warehouse = null, string $date = '2026-10-18'): Delivery
    {
        $line = $order->lines()->first();
        $delivery = Delivery::query()->create(['number' => 'DO-'.uniqid(), 'trans_date' => $date, 'customer_id' => $this->customer->id, 'branch_id' => $order->branch_id, 'taxable' => $order->taxable, 'inclusive_tax' => $order->inclusive_tax, 'created_by' => $this->owner->id]);
        $delivery->lines()->create(['sort' => 0, 'item_id' => $line->item_id, 'quantity' => $qty, 'unit_id' => $line->unit_id, 'base_quantity' => $qty, 'unit_price' => $line->unit_price, 'tax_code_id' => $line->tax_code_id,
            'warehouse_id' => $warehouse?->id ?? $line->warehouse_id, 'source_line_type' => 'sales_order_line', 'source_line_id' => $line->id]);
        $delivery->refreshTotal();
        $this->docs->created($delivery);

        return $delivery->fresh();
    }

    /** An invoice of the customer, straight from stock (no order behind it), approved on save. */
    protected function invoice(int $qty, int $price = 150_000, ?Item $item = null, ?string $date = null, ?Warehouse $warehouse = null): SalesInvoice
    {
        $item ??= $this->item;
        $invoice = SalesInvoice::query()->create(['number' => 'INV-'.uniqid(), 'trans_date' => $date ?? today()->toDateString(), 'customer_id' => $this->customer->id, 'branch_id' => $this->customer->branch_id, 'taxable' => true, 'inclusive_tax' => false,
            'payment_term_id' => $this->customer->payment_term_id, 'created_by' => $this->owner->id]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $item->id, 'quantity' => $qty, 'unit_id' => $item->unit1_id, 'base_quantity' => $qty, 'unit_price' => $price, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $warehouse?->id ?? $this->gudangJakarta->id]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        return $invoice->fresh();
    }
}
