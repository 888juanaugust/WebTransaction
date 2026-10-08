<?php

namespace Tests\Feature\Domain;

use App\Domain\Posting\DocumentRepository;
use App\Domain\Sales\PriceResolver;
use App\Domain\Settlement\EarlyPaymentDiscount;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\Sales\SalesReceipts\Pages\CreateSalesReceipt;
use App\Models\Company\PaymentTerm;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SellingPriceAdjustment;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Discount categories, wholesale quantity breaks, the minimum sale quantity and the early-payment discount. */
class PricingTest extends TestCase
{
    private Customer $customer;

    private Item $item;

    private PriceCategory $retail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->retail = PriceCategory::query()->where('is_default', true)->firstOrFail();
        $this->customer = $this->sampleCustomer();
        $this->item = $this->sampleItem(['use_wholesale_price' => true])->fresh();
    }

    /** @param  list<array{0: int, 1: int}>  $lines  [from quantity, value] */
    private function adjustment(PriceCategory $category, string $type, array $lines, string $date = '2026-11-01'): SellingPriceAdjustment
    {
        $adjustment = SellingPriceAdjustment::query()->create(['number' => 'PA-'.uniqid(), 'price_category_id' => $category->id, 'sales_adjustment_type' => $type, 'trans_date' => $date]);
        foreach ($lines as $sort => [$from, $value]) {
            $adjustment->lines()->create(['sort' => $sort, 'item_id' => $this->item->id, 'min_quantity' => $from, 'value' => $value]);
        }

        return $adjustment;
    }

    public function test_discount_adjustments_come_from_the_customers_discount_category(): void
    {
        $loyal = PriceCategory::query()->create(['name' => 'Loyal']);
        $this->adjustment($this->retail, SellingPriceAdjustment::DISCOUNT, [[0, 3]]);
        $this->adjustment($loyal, SellingPriceAdjustment::DISCOUNT, [[0, 7]]);

        $this->assertSame('3.0000', PriceResolver::resolve($this->customer, $this->item, null)['discount_percent'], 'without one, the price category\'s discounts');
        $this->customer->update(['discount_price_category_id' => $loyal->id]);
        $this->assertSame('7.0000', PriceResolver::resolve($this->customer->fresh(), $this->item, null)['discount_percent']);
        $this->assertSame('150000.0000', PriceResolver::resolve($this->customer->fresh(), $this->item, null)['price'], 'prices still from the item');
    }

    public function test_the_highest_wholesale_break_the_quantity_reaches_sets_the_price(): void
    {
        $this->adjustment($this->retail, SellingPriceAdjustment::PRICE, [[0, 150_000], [10, 140_000], [24, 130_000]]);

        $this->assertSame('150000.0000', PriceResolver::resolve($this->customer, $this->item, null, null, '5')['price']);
        $this->assertSame('140000.0000', PriceResolver::resolve($this->customer, $this->item, null, null, '12')['price']);
        $this->assertSame('130000.0000', PriceResolver::resolve($this->customer, $this->item, null, null, '30')['price']);
        $this->assertSame('150000.0000', PriceResolver::resolve($this->customer, $this->item, null)['price'], 'no quantity, no break');

        $this->item->update(['use_wholesale_price' => false]);
        $this->assertSame('150000.0000', PriceResolver::resolve($this->customer, $this->item->fresh(), null, null, '30')['price'], 'an item without wholesale prices ignores breaks');
    }

    public function test_the_invoice_grid_reprices_on_quantity_and_refuses_less_than_the_minimum_sale(): void
    {
        $this->adjustment($this->retail, SellingPriceAdjustment::PRICE, [[0, 150_000], [10, 140_000]]);
        $this->item->update(['min_sell_qty' => 3]);

        $page = Livewire::test(CreateSalesInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id, 'trans_date' => '2026-11-16'])
            ->set('data.lines', ['a' => ['item_id' => null, 'quantity' => 1, 'unit_id' => null, 'unit_price' => 0, 'discount_percent' => 0, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]])
            ->set('data.lines.a.item_id', $this->item->id)
            ->set('data.lines.a.quantity', 12);
        $this->assertEquals(140_000, (float) $page->get('data.lines.a.unit_price'), 'twelve reach the break of ten');

        $page->set('data.lines.a.quantity', 2)
            ->call('create')
            ->assertHasFormErrors(['lines.a.quantity']);
    }

    public function test_the_invoice_grid_takes_the_discount_in_force(): void
    {
        $this->adjustment($this->retail, SellingPriceAdjustment::DISCOUNT, [[0, 5]]);

        $page = Livewire::test(CreateSalesInvoice::class)
            ->fillForm(['customer_id' => $this->customer->id, 'trans_date' => '2026-11-16'])
            ->set('data.lines', ['a' => ['item_id' => null, 'quantity' => 1, 'unit_id' => null, 'unit_price' => 0, 'discount_percent' => 0, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]])
            ->set('data.lines.a.item_id', $this->item->id);
        $this->assertEquals(5, (float) $page->get('data.lines.a.discount_percent'), 'a discount-type price adjustment reaches the line');
        $this->assertEquals(150_000, (float) $page->get('data.lines.a.unit_price'));
    }

    public function test_paying_within_the_terms_discount_days_proposes_the_early_payment_discount(): void
    {
        $term = PaymentTerm::query()->create(['name' => 'Early payment 2%', 'discount_percent' => 2, 'discount_days' => 10, 'due_days' => 30]);
        $service = $this->sampleItem(['number' => 'SVC-1', 'name' => 'Fitting', 'item_type' => 'service']);
        $invoice = SalesInvoice::query()->create(['number' => 'INV-1', 'trans_date' => '2026-11-06', 'customer_id' => $this->customer->id, 'payment_term_id' => $term->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $service->id, 'quantity' => 1, 'unit_id' => $service->unit1_id, 'base_quantity' => 1, 'unit_price' => 1_000_000, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);

        $this->assertSame(['discount' => 20_000, 'pay' => 980_000], EarlyPaymentDiscount::propose($invoice->fresh(), 1_000_000, '2026-11-16'));
        $this->assertSame(['discount' => 0, 'pay' => 1_000_000], EarlyPaymentDiscount::propose($invoice->fresh(), 1_000_000, '2026-11-17'), 'one day late');

        $page = Livewire::test(CreateSalesReceipt::class)
            ->fillForm(['customer_id' => $this->customer->id, 'trans_date' => '2026-11-16', 'bank_account_id' => Account::query()->where('no', '1102')->value('id')])
            ->set('data.lines', ['a' => ['receivable_key' => null, 'amount' => 0, 'discount' => 0]])
            ->set('data.lines.a.receivable_key', "sales_invoice:{$invoice->id}");
        $this->assertSame(980_000, (int) $page->get('data.lines.a.amount'));
        $this->assertSame(20_000, (int) $page->get('data.lines.a.discount'));
    }
}
